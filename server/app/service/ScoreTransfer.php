<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class ScoreTransfer
{
    public static function transactionNo(string $prefix='TX'): string
    {
        return $prefix.date('YmdHis').strtoupper(bin2hex(random_bytes(5)));
    }

    public static function organizationAllocation(array $child,float $delta,array $operator=[]): void
    {
        if(abs($delta)<0.005)return;
        $childId=(int)$child['id'];$parentId=(int)$child['parent_id'];$tenantId=(int)$child['tenant_id'];$siteId=(int)$child['site_id'];
        if($childId<1||$tenantId<1||$siteId<1)throw new \InvalidArgumentException('组织账户信息不完整');
        $tx=self::transactionNo('AL');
        $lockedChild=Db::name('organization_nodes')->where('id',$childId)->where('tenant_id',$tenantId)->where('site_id',$siteId)->whereNull('deleted_at')->lock(true)->find();if(!$lockedChild)throw new \RuntimeException('下级组织不存在');
        if($parentId===0) {
            if((string)$lockedChild['level']!=='director')throw new \InvalidArgumentException('只有根总监可以直接从站点分配分数');
        } else {
            $parentCheck=Db::name('organization_nodes')->where('id',$parentId)->where('tenant_id',$tenantId)->where('site_id',$siteId)->whereNull('deleted_at')->find();
            if(!$parentCheck)throw new \RuntimeException('组织上级不存在');
            if(!OrganizationHierarchy::canParentLevelAccept((string)$parentCheck['level'],(string)$lockedChild['level']))throw new \InvalidArgumentException('组织层级关系无效，不能跨级分配分数');
        }
        if($delta<0 && (float)$lockedChild['balance']+0.000001<abs($delta))throw new \InvalidArgumentException('下级可用分数不足，已有分数已继续分配或产生亏损，不能收回这么多');
        if($parentId>0){
            $parent=Db::name('organization_nodes')->where('id',$parentId)->where('tenant_id',$tenantId)->where('site_id',$siteId)->whereNull('deleted_at')->lock(true)->find();if(!$parent)throw new \RuntimeException('上级组织不存在');
            if($delta>0&&(float)$parent['balance']+0.000001<$delta)throw new \InvalidArgumentException('上级可用分数不足，无法继续分配');
            self::changeOrganization($parent,-$delta,$tx,'层级分数分配',$childId,'organization',$operator);
        }else{
            $site=self::siteAccount($tenantId,$siteId,true);
            if($delta>0&&(float)$site['balance']+0.000001<$delta)throw new \InvalidArgumentException('站点可用分数不足，无法分配给该总监');
            self::changeSite($site,-$delta,$tx,$delta>0?'站点向总监分配分数':'站点收回总监分数',$childId,'organization',$operator);
        }
        self::changeOrganization($lockedChild,$delta,$tx,'收到上级分配分数',$parentId>0?$parentId:(int)$site['site_id'],$parentId>0?'organization':'site',$operator);
    }

    public static function userAllocation(array $user,float $delta,array $operator=[]): void
    {
        if(abs($delta)<0.005)return;
        $userId=(int)($user['id']??0);$siteId=(int)($user['site_id']??0);$tenantId=(int)($user['tenant_id']??0);
        if($userId<1||$siteId<1||$tenantId<1)throw new \InvalidArgumentException('用户账户信息不完整');
        $lockedUser=Db::name('site_users')->where('id',$userId)->where('site_id',$siteId)->where('tenant_id',$tenantId)->whereNull('deleted_at')->lock(true)->find();
        if(!$lockedUser)throw new \InvalidArgumentException('用户不存在或已停用');
        $lockedUser=DailyScoreUsage::normalize($lockedUser);
        $organizationId=(int)($lockedUser['organization_id']??0);if($organizationId<1)throw new \InvalidArgumentException('用户尚未归属代理');
        $agent=Db::name('organization_nodes')->where('id',$organizationId)->where('tenant_id',$tenantId)->where('site_id',$siteId)->where('level','agent')->where('status',1)->whereNull('deleted_at')->lock(true)->find();if(!$agent)throw new \InvalidArgumentException('用户所属代理不存在或已停用');
        if($delta>0&&(float)$agent['balance']+0.000001<$delta)throw new \InvalidArgumentException('代理可用分数不足，无法分配给用户');
        $available=(float)$lockedUser['balance']+(float)$lockedUser['credit_balance']-(float)$lockedUser['used_balance'];if($delta<0&&$available+0.000001<abs($delta))throw new \InvalidArgumentException('用户可用分数不足，不能收回这么多');
        self::transferBetweenOrganizationAndUser($agent,$lockedUser,$delta,$operator);
    }

    /**
     * Atomically set a member's cash and credit balances and book the net
     * movement against the member's direct agent. This is the only supported
     * path for administrative score edits.
     *
     * @return array{balance:string,credit_balance:string,used_balance:string,available_balance:string}
     */
    public static function setUserBalances(array $user,float $balance,float $creditBalance,array $operator=[]): array
    {
        $userId=(int)($user['id']??0);$siteId=(int)($user['site_id']??0);$tenantId=(int)($user['tenant_id']??0);
        if($userId<1||$siteId<1||$tenantId<1)throw new \InvalidArgumentException('会员账户信息不完整');
        if($balance<0||$creditBalance<0)throw new \InvalidArgumentException('余额和信用余额必须为非负数字');
        $locked=Db::name('site_users')->where('id',$userId)->where('site_id',$siteId)->where('tenant_id',$tenantId)->whereNull('deleted_at')->lock(true)->find();
        if(!$locked)throw new \RuntimeException('会员不存在或已停用');
        $locked=DailyScoreUsage::normalize($locked);
        $organizationId=(int)($locked['organization_id']??0);
        $oldTotal=(float)$locked['balance']+(float)$locked['credit_balance'];$newTotal=round($balance+$creditBalance,2);$delta=round($newTotal-$oldTotal,2);
        if($organizationId<1) {
            if(abs($delta)>=0.005)throw new \InvalidArgumentException('会员尚未归属代理，不能进行分数变更');
        } else {
            $agent=Db::name('organization_nodes')->where('id',$organizationId)->where('tenant_id',$tenantId)->where('site_id',$siteId)->where('level','agent')->where('status',1)->whereNull('deleted_at')->lock(true)->find();
            if(!$agent)throw new \RuntimeException('会员所属代理不存在或已停用');
            $available=$oldTotal-(float)$locked['used_balance'];
            if($delta<0&&$available+0.000001<abs($delta))throw new \InvalidArgumentException('会员有已下注锁定分数，不能收回这么多');
            if($delta>0&&(float)$agent['balance']+0.000001<$delta)throw new \InvalidArgumentException('代理可用分数不足，无法分配给会员');
            if(abs($delta)>=0.005)self::transferBetweenOrganizationAndUser($agent,$locked,$delta,$operator);
        }
        Db::name('site_users')->where('id',$userId)->update(['balance'=>number_format($balance,2,'.',''),'credit_balance'=>number_format($creditBalance,2,'.',''),'updated_at'=>date('Y-m-d H:i:s')]);
        $locked['balance']=$balance;$locked['credit_balance']=$creditBalance;
        return self::formattedUserBalances($locked);
    }

    /** @return array{balance:string,credit_balance:string,used_balance:string,available_balance:string} */
    private static function formattedUserBalances(array $user): array
    {
        $balance=round((float)($user['balance']??0),2);$credit=round((float)($user['credit_balance']??0),2);$used=round((float)($user['used_balance']??0),2);
        return ['balance'=>number_format($balance,2,'.',''),'credit_balance'=>number_format($credit,2,'.',''),'used_balance'=>number_format($used,2,'.',''),'available_balance'=>number_format(max(0,$balance+$credit-$used),2,'.','')];
    }

    /** Book both sides of a member allocation using one transaction number. */
    private static function transferBetweenOrganizationAndUser(array $agent,array $user,float $delta,array $operator): void
    {
        $tx=self::transactionNo('US');$beforeAgent=(float)$agent['balance'];$afterAgent=$beforeAgent-$delta;
        Db::name('organization_nodes')->where('id',(int)$agent['id'])->update(['balance'=>number_format($afterAgent,2,'.',''),'updated_at'=>date('Y-m-d H:i:s')]);
        CreditLedger::writeExtended(['tenant_id'=>(int)$agent['tenant_id'],'site_id'=>(int)$agent['site_id']],$tx,(int)$agent['id'],'organization',(int)$agent['id'],null,null,null,null,-$delta,$beforeAgent,$afterAgent,$delta>0?'向用户分配分数':'收回用户分数','score_allocation','allocation',$operator,'user',(int)$user['id'],null);
        $beforeUser=(float)$user['balance']+(float)$user['credit_balance']-(float)$user['used_balance'];$afterUser=$beforeUser+$delta;
        CreditLedger::writeExtended(['tenant_id'=>(int)$user['tenant_id'],'site_id'=>(int)$user['site_id']],$tx,(int)$agent['id'],'user',(int)$user['id'],(int)$user['id'],null,null,null,$delta,$beforeUser,$afterUser,$delta>0?'收到代理分配分数':'向代理归还分数','score_allocation','allocation',$operator,'organization',(int)$agent['id'],null);
    }

    public static function adjustPlatformTotal(int $tenantId,float $newTotal,array $operator=[],?string $note=null): array
    {
        if($newTotal<0)throw new \InvalidArgumentException('总平台总分不能小于0');$allocated=(float)Db::name('site_credit_accounts')->where('tenant_id',$tenantId)->sum('total_score');if($newTotal+0.000001<$allocated)throw new \InvalidArgumentException('新的总分不能低于已经分配给站点的分数');$account=self::platformAccount($tenantId,true);$delta=round($newTotal-(float)$account['total_score'],2);
        if($delta<0&&(float)$account['balance']+0.000001<abs($delta))throw new \InvalidArgumentException('总平台可用分数不足，已有分数已向下分配，不能降低到该数值');
        $before=(float)$account['balance'];$after=$before+$delta;$now=date('Y-m-d H:i:s');Db::name('platform_credit_accounts')->where('id',(int)$account['id'])->update(['total_score'=>number_format($newTotal,2,'.',''),'balance'=>number_format($after,2,'.',''),'updated_at'=>$now]);
        if(abs($delta)>=0.005)CreditLedger::writeExtended(['tenant_id'=>$tenantId,'site_id'=>0],self::transactionNo('PT'),null,'platform',(int)$account['id'],null,null,null,null,$delta,$before,$after,$delta>0?'设置总平台分数':'减少总平台总分','platform_total_adjustment','adjustment',$operator,null,null,$note);
        return ['total_score'=>number_format($newTotal,2,'.',''),'available_score'=>number_format($after,2,'.',''),'allocated_score'=>number_format($allocated,2,'.','')];
    }

    public static function adjustSiteTotal(array $site,float $newTotal,array $operator=[],?string $note=null): array
    {
        $siteId=(int)$site['id'];$tenantId=(int)$site['tenant_id'];
        if($newTotal<0)throw new \InvalidArgumentException('站点总分不能小于 0');
        $allocated=(float)Db::name('organization_nodes')->where('site_id',$siteId)->where('parent_id',0)->where('level','director')->whereNull('deleted_at')->sum('credit_limit');
        if($newTotal+0.000001<$allocated)throw new \InvalidArgumentException('站点总分不能低于已经分配给总监的分数');
        $platform=self::platformAccount($tenantId,true);$account=self::siteAccount($tenantId,$siteId,true);$delta=round($newTotal-(float)$account['total_score'],2);
        if($delta>0&&(float)$platform['balance']+0.000001<$delta)throw new \InvalidArgumentException('总平台可用分数不足，无法分配给站点');
        if($delta<0&&(float)$account['balance']+0.000001<abs($delta))throw new \InvalidArgumentException('站点可用分数不足，已有分数已分配给总监，不能收回这么多');
        if(abs($delta)>=0.005){
            $tx=self::transactionNo('ST');
            self::changePlatform($platform,-$delta,$siteId,$tx,$delta>0?'平台向站点分配分数':'平台收回站点分数',$siteId,'site',$operator);
            self::changeSite($account,$delta,$tx,$delta>0?'收到平台分配分数':'向平台归还分数',(int)$platform['id'],'platform',$operator);
        }
        Db::name('site_credit_accounts')->where('id',(int)$account['id'])->update(['total_score'=>number_format($newTotal,2,'.',''),'updated_at'=>date('Y-m-d H:i:s')]);
        return ['site_id'=>$siteId,'total_score'=>number_format($newTotal,2,'.',''),'available_score'=>number_format((float)$account['balance']+$delta,2,'.',''),'allocated_score'=>number_format($allocated,2,'.','')];
    }

    public static function platformAccount(int $tenantId,bool $lock=false): array
    {
        $query=Db::name('platform_credit_accounts')->where('tenant_id',$tenantId);if($lock)$query->lock(true);$row=$query->find();if($row)return$row;$now=date('Y-m-d H:i:s');$id=(int)Db::name('platform_credit_accounts')->insertGetId(['tenant_id'=>$tenantId,'total_score'=>'0.00','balance'=>'0.00','created_at'=>$now,'updated_at'=>$now]);return['id'=>$id,'tenant_id'=>$tenantId,'total_score'=>0,'balance'=>0];
    }

    public static function siteAccount(int $tenantId,int $siteId,bool $lock=false): array
    {
        $query=Db::name('site_credit_accounts')->where('site_id',$siteId);if($lock)$query->lock(true);$row=$query->find();if($row)return$row;
        $now=date('Y-m-d H:i:s');$id=(int)Db::name('site_credit_accounts')->insertGetId(['tenant_id'=>$tenantId,'site_id'=>$siteId,'total_score'=>'0.00','balance'=>'0.00','created_at'=>$now,'updated_at'=>$now]);
        return['id'=>$id,'tenant_id'=>$tenantId,'site_id'=>$siteId,'total_score'=>0,'balance'=>0];
    }

    private static function changeOrganization(array $node,float $delta,string $tx,string $reason,int $counterpartyId,string $counterpartyType,array $operator): void
    {
        $before=(float)$node['balance'];$after=$before+$delta;Db::name('organization_nodes')->where('id',(int)$node['id'])->update(['balance'=>number_format($after,2,'.',''),'updated_at'=>date('Y-m-d H:i:s')]);
        CreditLedger::writeExtended(['tenant_id'=>(int)$node['tenant_id'],'site_id'=>(int)$node['site_id']],$tx,(int)$node['id'],'organization',(int)$node['id'],null,null,null,null,$delta,$before,$after,$reason,'score_allocation','allocation',$operator,$counterpartyType,$counterpartyId,null);
    }
    private static function changePlatform(array $account,float $delta,int $siteId,string $tx,string $reason,int $counterpartyId,string $counterpartyType,array $operator): void
    {
        $before=(float)$account['balance'];$after=$before+$delta;Db::name('platform_credit_accounts')->where('id',(int)$account['id'])->update(['balance'=>number_format($after,2,'.',''),'updated_at'=>date('Y-m-d H:i:s')]);
        CreditLedger::writeExtended(['tenant_id'=>(int)$account['tenant_id'],'site_id'=>$siteId],$tx,null,'platform',(int)$account['id'],null,null,null,null,$delta,$before,$after,$reason,'score_allocation','allocation',$operator,$counterpartyType,$counterpartyId,null);
    }
    private static function changeSite(array $account,float $delta,string $tx,string $reason,int $counterpartyId,string $counterpartyType,array $operator): void
    {
        $before=(float)$account['balance'];$after=$before+$delta;Db::name('site_credit_accounts')->where('id',(int)$account['id'])->update(['balance'=>number_format($after,2,'.',''),'updated_at'=>date('Y-m-d H:i:s')]);
        CreditLedger::writeExtended(['tenant_id'=>(int)$account['tenant_id'],'site_id'=>(int)$account['site_id']],$tx,null,'site',(int)$account['id'],null,null,null,null,$delta,$before,$after,$reason,'score_allocation','allocation',$operator,$counterpartyType,$counterpartyId,null);
    }
}
