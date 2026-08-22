<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class InterceptionAllocator
{
    private const FOLLOW_SETTING_KEY = 'agent_follow_platform_interception';

    public function allocate(array $context): array
    {
        $tenantId=(int)($context['tenant_id']??0); $siteId=(int)($context['site_id']??0); $userId=(int)($context['user_id']??0);
        $lotteryId=(int)($context['lottery_id']??0); $odds=$context['odds']??[]; $oddsId=(int)($odds['id']??0);
        $issue=trim((string)($context['issue_no']??'')); $actual=max(0,(float)($context['amount']??0));
        if($tenantId<1||$siteId<1||$userId<1||$lotteryId<1||$oddsId<1||$issue===''||$actual<=0||!is_array($odds)) return [];

        $rate=max(0,min(100,(float)Db::name('site_users')->where('id',$userId)->where('site_id',$siteId)->value('interception_rate')));
        $amounts=json_decode((string)Db::name('settings')->where('site_id',$siteId)->where('key','agent_interception_amounts_'.$lotteryId)->value('value'),true);
        $siteLimit=max(0,(float)(is_array($amounts)?($amounts[(string)$oddsId]??0):0));
        if($rate<=0||$siteLimit<=0) return [];

        $follow=(int)Db::name('settings')->where('site_id',$siteId)->where('key',self::FOLLOW_SETTING_KEY)->value('value')===1;
        $platformLimit=max(0,(float)($odds['platform_single_item_limit']??$odds['single_item_limit']??0));
        $numbers=$this->numbers((string)($context['number_text']??''));
        if($numbers===[]) return [];
        $stake=$actual/count($numbers); $now=date('Y-m-d H:i:s'); $results=[];
        foreach($numbers as $number) {
            $wanted=round($stake*$rate/100,2);
            $siteTaken=$this->reserve('site',$siteId,$tenantId,$lotteryId,$issue,$oddsId,$number,$siteLimit,$wanted,$now);
            $taken=$siteTaken; $reason=$siteTaken+0.000001<$wanted?'site_full':'allocated';
            if($follow&&$siteTaken>0&&$platformLimit>0) {
                $platformTaken=$this->reserve('platform',$tenantId,$tenantId,$lotteryId,$issue,$oddsId,$number,$platformLimit,$siteTaken,$now);
                if($platformTaken+0.000001<$siteTaken) {
                    $this->releaseCapacity('site',$siteId,$tenantId,$lotteryId,$issue,$oddsId,$number,$siteTaken-$platformTaken,$now);
                    $reason=$platformTaken<=0?'platform_full':'platform_partial';
                }
                $taken=$platformTaken;
            }
            Db::name('agent_interceptions')->insert([
                'tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$userId,'lottery_id'=>$lotteryId,
                'lottery_odds_id'=>$oddsId,'bet_record_id'=>(int)($context['bet_record_id']??0),'bet_detail_id'=>(int)($context['bet_detail_id']??0),
                'issue_no'=>$issue,'number_key'=>$number,'bet_amount'=>number_format($stake,2,'.',''),'share_rate'=>number_format($rate,4,'.',''),
                'requested_amount'=>number_format($wanted,2,'.',''),'intercepted_amount'=>number_format($taken,2,'.',''),
                'site_limit'=>number_format($siteLimit,2,'.',''),'platform_limit'=>number_format($platformLimit,2,'.',''),
                'follow_platform'=>$follow?1:0,'allocation_status'=>$reason,'created_at'=>$now,
            ]);
            $results[]=['number'=>$number,'requested'=>$wanted,'intercepted'=>$taken,'status'=>$reason];
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
                $this->releaseCapacity('site',(int)$row['site_id'],(int)$row['tenant_id'],(int)$row['lottery_id'],(string)$row['issue_no'],(int)$row['lottery_odds_id'],(string)$row['number_key'],$amount,$now);
                if((int)$row['follow_platform']===1&&(float)$row['platform_limit']>0) $this->releaseCapacity('platform',(int)$row['tenant_id'],(int)$row['tenant_id'],(int)$row['lottery_id'],(string)$row['issue_no'],(int)$row['lottery_odds_id'],(string)$row['number_key'],$amount,$now);
            }
            Db::name('agent_interceptions')->where('id',(int)$row['id'])->update(['allocation_status'=>'refunded','released_at'=>$now]);
        }
    }

    private function reserve(string $scopeType, int $scopeId, int $tenantId, int $lotteryId, string $issue, int $oddsId, string $number, float $limit, float $wanted, string $now): float
    {
        if($limit<=0||$wanted<=0) return 0.0;
        Db::execute('INSERT IGNORE INTO `interception_capacity_usage` (`tenant_id`,`scope_type`,`scope_id`,`lottery_id`,`lottery_odds_id`,`issue_no`,`number_key`,`used_amount`,`updated_at`) VALUES (?,?,?,?,?,?,?,0,?)',[$tenantId,$scopeType,$scopeId,$lotteryId,$oddsId,$issue,$number,$now]);
        $row=Db::name('interception_capacity_usage')->where(['tenant_id'=>$tenantId,'scope_type'=>$scopeType,'scope_id'=>$scopeId,'lottery_id'=>$lotteryId,'lottery_odds_id'=>$oddsId,'issue_no'=>$issue,'number_key'=>$number])->lock(true)->find();
        $take=min($wanted,max(0,$limit-(float)($row['used_amount']??0)));
        if($take>0) Db::name('interception_capacity_usage')->where('id',(int)$row['id'])->update(['used_amount'=>Db::raw('used_amount + '.number_format($take,4,'.','')),'updated_at'=>$now]);
        return $take;
    }

    private function releaseCapacity(string $scopeType, int $scopeId, int $tenantId, int $lotteryId, string $issue, int $oddsId, string $number, float $amount, string $now): void
    {
        if($amount<=0) return;
        Db::name('interception_capacity_usage')->where(['tenant_id'=>$tenantId,'scope_type'=>$scopeType,'scope_id'=>$scopeId,'lottery_id'=>$lotteryId,'lottery_odds_id'=>$oddsId,'issue_no'=>$issue,'number_key'=>$number])->update(['used_amount'=>Db::raw('GREATEST(used_amount - '.number_format($amount,4,'.','').', 0)'),'updated_at'=>$now]);
    }

    private function numbers(string $text): array
    {
        $parts=preg_split('/[\s,，]+/u',trim($text),-1,PREG_SPLIT_NO_EMPTY)?:[];
        $parts=array_values(array_unique(array_map(static fn(string $value): string=>mb_substr(trim($value),0,64),$parts)));
        return array_values(array_filter($parts,static fn(string $value): bool=>$value!==''));
    }
}
