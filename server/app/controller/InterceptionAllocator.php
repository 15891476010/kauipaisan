<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class InterceptionAllocator
{
    private const FOLLOW_SETTING_KEY = 'agent_follow_platform_interception';
    /** Capacity is scoped to one lottery/play/issue, not to each number. */
    private const CAPACITY_NUMBER_KEY = '__PLAY_TOTAL__';

    public function agentConfiguration(int $tenantId,int $siteId,int $organizationId,int $lotteryId,bool $lock=false,string $boardCode='A'): array
    {
        $nodeQuery=Db::name('organization_nodes')->where('id',$organizationId)->where('tenant_id',$tenantId)->where('site_id',$siteId)
            ->where('level','agent')->where('status',1)->whereNull('deleted_at');
        if($lock)$nodeQuery->lock(true);
        $node=$nodeQuery->find();
        if(!$node)throw new \RuntimeException('会员未归属有效代理，无法执行拦货分配');
        $settings=json_decode((string)($node['settings']??''),true);$settings=is_array($settings)?$settings:[];
        $boardCode=$this->boardCode($boardCode);$amountKey=$this->amountKey($lotteryId,$boardCode);$source='organization';
        if(array_key_exists($amountKey,$settings))$amounts=is_array($settings[$amountKey])?$settings[$amountKey]:[];
        else{$amounts=json_decode((string)Db::name('settings')->where('site_id',$siteId)->where('key',$amountKey)->value('value'),true);$amounts=is_array($amounts)?$amounts:[];$source='legacy_site_fallback';}
        if(array_key_exists(self::FOLLOW_SETTING_KEY,$settings))$follow=(int)$settings[self::FOLLOW_SETTING_KEY]===1;
        else{$follow=(int)Db::name('settings')->where('site_id',$siteId)->where('key',self::FOLLOW_SETTING_KEY)->value('value')===1;$source=$source==='organization'?'organization_with_legacy_follow_fallback':$source;}
        return ['organization_id'=>$organizationId,'board_code'=>$boardCode,'amounts'=>$amounts,'follow'=>$follow,'source'=>$source];
    }

    public function allocate(array $context): array
    {
        $tenantId=(int)($context['tenant_id']??0); $siteId=(int)($context['site_id']??0); $userId=(int)($context['user_id']??0);
        $lotteryId=(int)($context['lottery_id']??0); $boardCode=$this->boardCode((string)($context['board_code']??'A')); $odds=$context['odds']??[]; $oddsId=(int)($odds['id']??0);
        $issue=trim((string)($context['issue_no']??'')); $actual=max(0,(float)($context['amount']??0));
        if($tenantId<1||$siteId<1||$userId<1||$lotteryId<1||$oddsId<1||$issue===''||$actual<=0||!is_array($odds)) return [];

        $user=Db::name('site_users')->where('id',$userId)->where('site_id',$siteId)->whereNull('deleted_at')->lock(true)->find();
        if(!$user)throw new \RuntimeException('拦货会员不存在或已停用');
        $organizationId=(int)($user['organization_id']??0);
        $configuration=$this->agentConfiguration($tenantId,$siteId,$organizationId,$lotteryId,true,$boardCode);
        $rate=max(0,min(100,(float)($user['interception_rate']??0)));
        $agentLimit=max(0,(float)($configuration['amounts'][(string)$oddsId]??0));
        if($rate<=0||$agentLimit<=0) return [];

        $follow=(bool)$configuration['follow'];
        $platformLimit=max(0,(float)($odds['platform_single_item_limit']??$odds['single_item_limit']??0));
        $stakes=$this->stakes((string)($context['number_text']??''),$actual);
        if($stakes===[]) return [];
        $now=date('Y-m-d H:i:s'); $results=[];
        foreach($stakes as $entry) {
            $number=$entry['number'];$stake=$entry['amount'];
            $wanted=round($stake*$rate/100,2);
            $agentTaken=$this->reserve('organization',$organizationId,$tenantId,$lotteryId,$boardCode,$issue,$oddsId,self::CAPACITY_NUMBER_KEY,$agentLimit,$wanted,$now);
            $taken=$agentTaken; $reason=$agentTaken+0.000001<$wanted?'agent_full':'allocated';
            if($follow&&$agentTaken>0&&$platformLimit>0) {
                $platformTaken=$this->reserve('platform',$tenantId,$tenantId,$lotteryId,$boardCode,$issue,$oddsId,self::CAPACITY_NUMBER_KEY,$platformLimit,$agentTaken,$now);
                if($platformTaken+0.000001<$agentTaken) {
                    $this->releaseCapacity('organization',$organizationId,$tenantId,$lotteryId,$boardCode,$issue,$oddsId,self::CAPACITY_NUMBER_KEY,$agentTaken-$platformTaken,$now);
                    $reason=$platformTaken<=0?'platform_full':'platform_partial';
                }
                $taken=$platformTaken;
            }
            Db::name('agent_interceptions')->insert([
                'tenant_id'=>$tenantId,'site_id'=>$siteId,'organization_id'=>$organizationId,'user_id'=>$userId,'lottery_id'=>$lotteryId,'board_code'=>$boardCode,
                'lottery_odds_id'=>$oddsId,'bet_record_id'=>(int)($context['bet_record_id']??0),'bet_detail_id'=>(int)($context['bet_detail_id']??0),
                'issue_no'=>$issue,'number_key'=>$number,'bet_amount'=>number_format($stake,2,'.',''),'share_rate'=>number_format($rate,4,'.',''),
                'requested_amount'=>number_format($wanted,2,'.',''),'intercepted_amount'=>number_format($taken,2,'.',''),
                'site_limit'=>number_format($agentLimit,2,'.',''),'platform_limit'=>number_format($platformLimit,2,'.',''),
                'follow_platform'=>$follow?1:0,'allocation_status'=>$reason,'created_at'=>$now,
            ]);
            $results[]=['number'=>$number,'requested'=>$wanted,'intercepted'=>$taken,'status'=>$reason,'organization_id'=>$organizationId,'configuration_source'=>$configuration['source']];
        }
        return $results;
    }

    public function releaseForRecord(int $betRecordId): void
    {
        if($betRecordId<1) return;
        $rows=Db::name('agent_interceptions')->where('bet_record_id',$betRecordId)->whereNull('released_at')->lock(true)->select()->toArray();
        $now=date('Y-m-d H:i:s');
        foreach($rows as $row) {
            $amount=(float)$row['intercepted_amount'];
            if($amount>0) {
                $organizationId=(int)($row['organization_id']??0);
                if($organizationId<1)throw new \RuntimeException('历史拦货记录缺少代理归属，无法安全释放容量');
                $boardCode=$this->boardCode((string)($row['board_code']??'A'));
                $this->releaseCapacity('organization',$organizationId,(int)$row['tenant_id'],(int)$row['lottery_id'],$boardCode,(string)$row['issue_no'],(int)$row['lottery_odds_id'],self::CAPACITY_NUMBER_KEY,$amount,$now);
                if((int)$row['follow_platform']===1&&(float)$row['platform_limit']>0) $this->releaseCapacity('platform',(int)$row['tenant_id'],(int)$row['tenant_id'],(int)$row['lottery_id'],$boardCode,(string)$row['issue_no'],(int)$row['lottery_odds_id'],self::CAPACITY_NUMBER_KEY,$amount,$now);
            }
            Db::name('agent_interceptions')->where('id',(int)$row['id'])->update(['allocation_status'=>'refunded','released_at'=>$now]);
        }
    }

    private function reserve(string $scopeType, int $scopeId, int $tenantId, int $lotteryId, string $boardCode, string $issue, int $oddsId, string $number, float $limit, float $wanted, string $now): float
    {
        if($limit<=0||$wanted<=0) return 0.0;
        Db::execute('INSERT IGNORE INTO `interception_capacity_usage` (`tenant_id`,`scope_type`,`scope_id`,`lottery_id`,`board_code`,`lottery_odds_id`,`issue_no`,`number_key`,`used_amount`,`updated_at`) VALUES (?,?,?,?,?,?,?,?,0,?)',[$tenantId,$scopeType,$scopeId,$lotteryId,$boardCode,$oddsId,$issue,$number,$now]);
        $row=Db::name('interception_capacity_usage')->where(['tenant_id'=>$tenantId,'scope_type'=>$scopeType,'scope_id'=>$scopeId,'lottery_id'=>$lotteryId,'board_code'=>$boardCode,'lottery_odds_id'=>$oddsId,'issue_no'=>$issue,'number_key'=>$number])->lock(true)->find();
        $take=min($wanted,max(0,$limit-(float)($row['used_amount']??0)));
        if($take>0) Db::name('interception_capacity_usage')->where('id',(int)$row['id'])->update(['used_amount'=>Db::raw('used_amount + '.number_format($take,4,'.','')),'updated_at'=>$now]);
        return $take;
    }

    private function releaseCapacity(string $scopeType, int $scopeId, int $tenantId, int $lotteryId, string $boardCode, string $issue, int $oddsId, string $number, float $amount, string $now): void
    {
        if($amount<=0) return;
        Db::name('interception_capacity_usage')->where(['tenant_id'=>$tenantId,'scope_type'=>$scopeType,'scope_id'=>$scopeId,'lottery_id'=>$lotteryId,'board_code'=>$boardCode,'lottery_odds_id'=>$oddsId,'issue_no'=>$issue,'number_key'=>$number])->update(['used_amount'=>Db::raw('GREATEST(used_amount - '.number_format($amount,4,'.','').', 0)'),'updated_at'=>$now]);
    }

    private function boardCode(string $value): string
    {
        $value=strtoupper(trim($value));
        return preg_match('/^[A-Z][A-Z0-9_]{0,7}$/',$value)===1?$value:'A';
    }

    private function amountKey(int $lotteryId,string $boardCode): string
    {
        return $boardCode==='A'?'agent_interception_amounts_'.$lotteryId:'agent_interception_amounts_'.$boardCode.'_'.$lotteryId;
    }

    private function numbers(string $text): array
    {
        $parts=preg_split('/[\s,，]+/u',trim($text),-1,PREG_SPLIT_NO_EMPTY)?:[];
        // Keep every stake occurrence. The same number may be intentionally
        // entered more than once, and each occurrence carries its own amount.
        $parts=array_map(static fn(string $value): string=>mb_substr(trim($value),0,64),$parts);
        return array_values(array_filter($parts,static fn(string $value): bool=>$value!==''));
    }

    /** @return array<int,array{number:string,amount:float}> */
    private function stakes(string $numberText,float $actual): array
    {
        $numbers=$this->numbers($numberText);
        if($numbers===[]||$actual<=0) return [];
        $amount=$actual/count($numbers);
        return array_map(static fn(string $number): array=>['number'=>$number,'amount'=>$amount],$numbers);
    }
}
