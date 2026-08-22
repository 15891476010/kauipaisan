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
        $childId=(int)$child['id'];$parentId=(int)$child['parent_id'];$tenantId=(int)$child['tenant_id'];$siteId=(int)$child['site_id'];$tx=self::transactionNo('AL');
        $lockedChild=Db::name('organization_nodes')->where('id',$childId)->lock(true)->find();if(!$lockedChild)throw new \RuntimeException('下级组织不存在');
        if($delta<0 && (float)$lockedChild['balance']+0.000001<abs($delta))throw new \InvalidArgumentException('下级可用分数不足，已有分数已继续分配或产生亏损，不能收回这么多');
        if($parentId>0){
            $parent=Db::name('organization_nodes')->where('id',$parentId)->lock(true)->find();if(!$parent)throw new \RuntimeException('上级组织不存在');
            if($delta>0&&(float)$parent['balance']+0.000001<$delta)throw new \InvalidArgumentException('上级可用分数不足，无法继续分配');
            self::changeOrganization($parent,-$delta,$tx,'层级分数分配',$childId,'organization',$operator);
        }else{
            $platform=self::platformAccount($tenantId,true);if($delta>0&&(float)$platform['balance']+0.000001<$delta)throw new \InvalidArgumentException('总平台可用分数不足，无法分配给股东');
            self::changePlatform($platform,-$delta,$siteId,$tx,'平台向股东分配分数',$childId,'organization',$operator);
        }
        self::changeOrganization($lockedChild,$delta,$tx,'收到上级分配分数',$parentId,$parentId>0?'organization':'platform',$operator);
    }

    public static function userAllocation(array $user,float $delta,array $operator=[]): void
    {
        if(abs($delta)<0.005)return;$organizationId=(int)($user['organization_id']??0);if($organizationId<1)throw new \InvalidArgumentException('用户尚未归属代理');
        $agent=Db::name('organization_nodes')->where('id',$organizationId)->where('level','agent')->lock(true)->find();if(!$agent)throw new \InvalidArgumentException('用户所属代理不存在');
        if($delta>0&&(float)$agent['balance']+0.000001<$delta)throw new \InvalidArgumentException('代理可用分数不足，无法分配给用户');
        $available=(float)$user['balance']+(float)$user['credit_balance']-(float)$user['used_balance'];if($delta<0&&$available+0.000001<abs($delta))throw new \InvalidArgumentException('用户可用分数不足，不能收回这么多');
        $tx=self::transactionNo('US');self::changeOrganization($agent,-$delta,$tx,$delta>0?'向用户分配分数':'收回用户分数',(int)$user['id'],'user',$operator);
        CreditLedger::writeExtended(['tenant_id'=>(int)$user['tenant_id'],'site_id'=>(int)$user['site_id']],$tx,$organizationId,'user',(int)$user['id'],(int)$user['id'],null,null,null,$delta,$available,$available+$delta,$delta>0?'收到代理分配分数':'向代理归还分数','score_allocation','allocation',$operator,'organization',$organizationId,null);
    }

    public static function adjustPlatformTotal(int $tenantId,float $newTotal,array $operator=[],?string $note=null): array
    {
        if($newTotal<0)throw new \InvalidArgumentException('总平台总分不能小于0');$allocated=(float)Db::name('organization_nodes')->where('tenant_id',$tenantId)->where('parent_id',0)->whereNull('deleted_at')->sum('credit_limit');if($newTotal+0.000001<$allocated)throw new \InvalidArgumentException('新的总分不能低于已经分配给股东的分数');$account=self::platformAccount($tenantId,true);$delta=round($newTotal-(float)$account['total_score'],2);
        if($delta<0&&(float)$account['balance']+0.000001<abs($delta))throw new \InvalidArgumentException('总平台可用分数不足，已有分数已向下分配，不能降低到该数值');
        $before=(float)$account['balance'];$after=$before+$delta;$now=date('Y-m-d H:i:s');Db::name('platform_credit_accounts')->where('id',(int)$account['id'])->update(['total_score'=>number_format($newTotal,2,'.',''),'balance'=>number_format($after,2,'.',''),'updated_at'=>$now]);
        if(abs($delta)>=0.005)CreditLedger::writeExtended(['tenant_id'=>$tenantId,'site_id'=>0],self::transactionNo('PT'),null,'platform',(int)$account['id'],null,null,null,null,$delta,$before,$after,$delta>0?'设置总平台分数':'减少总平台总分','platform_total_adjustment','adjustment',$operator,null,null,$note);
        return ['total_score'=>number_format($newTotal,2,'.',''),'available_score'=>number_format($after,2,'.',''),'allocated_score'=>number_format($allocated,2,'.','')];
    }

    public static function platformAccount(int $tenantId,bool $lock=false): array
    {
        $query=Db::name('platform_credit_accounts')->where('tenant_id',$tenantId);if($lock)$query->lock(true);$row=$query->find();if($row)return$row;$now=date('Y-m-d H:i:s');$id=(int)Db::name('platform_credit_accounts')->insertGetId(['tenant_id'=>$tenantId,'total_score'=>'0.00','balance'=>'0.00','created_at'=>$now,'updated_at'=>$now]);return['id'=>$id,'tenant_id'=>$tenantId,'total_score'=>0,'balance'=>0];
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
}
