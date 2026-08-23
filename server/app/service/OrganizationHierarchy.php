<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class OrganizationHierarchy
{
    public const LEVELS = ['director','shareholder','small_shareholder','general_agent','agent'];
    public const LABELS = ['director'=>'总监','shareholder'=>'大股东','small_shareholder'=>'小股东','general_agent'=>'总代理','agent'=>'代理'];
    public const CHILD_LEVELS = [
        'director' => ['shareholder'],
        'shareholder' => ['small_shareholder'],
        'small_shareholder' => ['general_agent'],
        'general_agent' => ['agent'],
        'agent' => [],
    ];
    public const PERMISSIONS = [
        'overview'=>'数据概览','order_details'=>'总货明细','winning_details'=>'中奖明细','bet_details'=>'投注明细',
        'contribution'=>'贡献度','daily_ledger'=>'日分类账','monthly_ledger'=>'月分类账','daily_path'=>'日路径账','monthly_path'=>'月路径账',
        'reports'=>'报表','monthly_reports'=>'月报表','results'=>'开奖号码','subordinates'=>'下级管理',
        'interception_details'=>'拦货明细','interception_winning'=>'拦货中奖','interception_plate'=>'拦货盘面',
        'rules'=>'规则说明','settings'=>'业务设置','logs'=>'日志','subaccounts'=>'子账号','organization.manage'=>'下级管理',
    ];

    public static function nextLevel(string $level): ?string
    {
        return self::CHILD_LEVELS[$level][0] ?? null;
    }

    public static function childLevels(string $level): array
    {
        return self::CHILD_LEVELS[$level] ?? [];
    }

    public static function canParentLevelAccept(string $parentLevel, string $childLevel): bool
    {
        return in_array($childLevel, self::childLevels($parentLevel), true);
    }

    public static function normalizePermissions(mixed $value, array $parentPermissions=['*']): array
    {
        $items=is_array($value)?$value:[];
        $items=array_values(array_unique(array_filter(array_map('strval',$items),static fn(string $item): bool=>$item==='*'||isset(self::PERMISSIONS[$item]))));
        if (in_array('*',$parentPermissions,true)) return $items ?: ['*'];
        return array_values(array_intersect($items,$parentPermissions));
    }

    public static function decodePermissions(mixed $value): array
    {
        if (is_array($value)) return array_values(array_map('strval',$value));
        $decoded=is_string($value)?json_decode($value,true):null;
        return is_array($decoded)?array_values(array_map('strval',$decoded)):[];
    }

    public static function rootForSite(int $siteId): ?array
    {
        $query=Db::name('organization_nodes')->where('site_id',$siteId)->where('parent_id',0)->whereNull('deleted_at');
        return $query->where('level','director')->find() ?: null;
    }

    public static function accountContext(array $account): array
    {
        $node=Db::name('organization_nodes')->where('id',(int)$account['organization_id'])->where('site_id',(int)$account['site_id'])->where('status',1)->whereNull('deleted_at')->find();
        if (!$node) throw new \RuntimeException('当前组织已停用或删除');
        $permissions=self::effectivePermissions((int)$node['id'],self::decodePermissions($account['permissions']??null));
        return ['node'=>$node,'permissions'=>$permissions ?: []];
    }

    public static function effectivePermissions(int $organizationId, array $accountPermissions=[]): array
    {
        $node=Db::name('organization_nodes')->where('id',$organizationId)->whereNull('deleted_at')->find();
        if (!$node) return [];
        $chain=[]; $cursor=$node;
        while($cursor){$chain[]=$cursor;$parentId=(int)$cursor['parent_id'];$cursor=$parentId>0?Db::name('organization_nodes')->where('id',$parentId)->whereNull('deleted_at')->find():null;}
        $effective=['*'];
        foreach(array_reverse($chain) as $item){$allowed=self::decodePermissions($item['permissions']??null);if(!$allowed)$allowed=[];if(!in_array('*',$effective,true))$effective=array_values(array_intersect($effective,$allowed));elseif(!in_array('*',$allowed,true))$effective=$allowed;}
        if(!in_array('*',$accountPermissions,true))$effective=in_array('*',$effective,true)?$accountPermissions:array_values(array_intersect($effective,$accountPermissions));
        return $effective;
    }

    public static function rebuildPath(int $id): void
    {
        $node=Db::name('organization_nodes')->where('id',$id)->find();
        if (!$node) return;
        $parent=(int)$node['parent_id']>0?Db::name('organization_nodes')->where('id',(int)$node['parent_id'])->find():null;
        $path=$parent?(string)$parent['path'].$id.'/':'/'.$id.'/';
        Db::name('organization_nodes')->where('id',$id)->update(['path'=>$path,'depth'=>$parent?(int)$parent['depth']+1:1]);
    }

    public static function rebuildBranch(int $id): void
    {
        self::rebuildPath($id);
        foreach(Db::name('organization_nodes')->where('parent_id',$id)->whereNull('deleted_at')->column('id') as $childId)self::rebuildBranch((int)$childId);
    }

    public static function nodeForSession(array $session): ?array
    {
        $siteId=(int)($session['site_id']??0);
        if($siteId<1)return null;
        $organizationId=(int)($session['organization_id']??0);
        $node=$organizationId>0?Db::name('organization_nodes')->where('id',$organizationId)->where('site_id',$siteId)->whereNull('deleted_at')->find():null;
        return $node ?: self::rootForSite($siteId);
    }

    public static function descendantIds(int $organizationId): array
    {
        $node=Db::name('organization_nodes')->where('id',$organizationId)->whereNull('deleted_at')->find();
        if(!$node)return [];
        return array_map('intval',Db::name('organization_nodes')->where('site_id',(int)$node['site_id'])->whereLike('path',(string)$node['path'].'%')->whereNull('deleted_at')->column('id'));
    }

    public static function visibleUserIds(array $session): array
    {
        $siteId=(int)($session['site_id']??0);
        if($siteId<1)return [];
        $node=self::nodeForSession($session);
        if(!$node)return array_map('intval',Db::name('site_users')->where('site_id',$siteId)->whereNull('deleted_at')->column('id'));
        $organizationIds=self::descendantIds((int)$node['id']);
        $query=Db::name('site_users')->where('site_id',$siteId)->whereNull('deleted_at');
        $isRoot=(int)$node['parent_id']===0;
        $query->where(function($nested)use($organizationIds,$isRoot):void{
            $nested->whereIn('organization_id',$organizationIds?:[0]);
            if($isRoot)$nested->whereOrRaw('organization_id IS NULL');
        });
        return array_map('intval',$query->column('id'));
    }

    public static function applyUserScope(mixed $query,array $session,string $field='user_id'): mixed
    {
        $ids=self::visibleUserIds($session);
        return $ids?$query->whereIn($field,$ids):$query->whereRaw('1=0');
    }

    public static function assertVisibleUser(array $session,int $userId): array
    {
        if(!in_array($userId,self::visibleUserIds($session),true))throw new \InvalidArgumentException('会员不存在或不在当前组织数据范围内');
        $user=Db::name('site_users')->where('id',$userId)->where('site_id',(int)$session['site_id'])->whereNull('deleted_at')->find();
        if(!$user)throw new \InvalidArgumentException('会员不存在');
        return $user;
    }

    public static function agentCreditSummary(int $organizationId,?int $excludeUserId=null): array
    {
        $node=Db::name('organization_nodes')->where('id',$organizationId)->where('level','agent')->whereNull('deleted_at')->find();
        if(!$node)throw new \InvalidArgumentException('只有代理层级可以分配会员额度');
        $query=Db::name('site_users')->where('organization_id',$organizationId)->whereNull('deleted_at');
        if($excludeUserId!==null)$query->where('id','<>',$excludeUserId);
        $directMemberCredit=(float)$query->sum('credit_balance');
        return self::creditSummary($node,0.0,$directMemberCredit,0.0,0.0);
    }

    public static function nodeCreditSummary(int $organizationId): array
    {
        $node=Db::name('organization_nodes')->where('id',$organizationId)->whereNull('deleted_at')->find();
        if(!$node)return self::emptyCreditSummary();
        if((string)$node['level']==='agent')return self::agentCreditSummary($organizationId);
        $directChildCredit=(float)Db::name('organization_nodes')->where('parent_id',$organizationId)->whereNull('deleted_at')->sum('credit_limit');
        $directMemberCredit=(float)Db::name('site_users')->where('organization_id',$organizationId)->whereNull('deleted_at')->sum('credit_balance');
        $unassignedCredit=0.0;$unassignedNet=0.0;
        if((string)$node['level']==='director'&&(int)$node['parent_id']===0){
            $unassigned=Db::name('site_users')->where('site_id',(int)$node['site_id'])->whereNull('organization_id')->whereNull('deleted_at');
            $unassignedCredit=(float)(clone $unassigned)->sum('credit_balance');
            $row=(clone $unassigned)->fieldRaw('COALESCE(SUM(credit_balance + balance),0) AS net_score')->find();
            $unassignedNet=(float)($row['net_score']??0);
        }
        return self::creditSummary($node,$directChildCredit,$directMemberCredit,$unassignedCredit,$unassignedNet);
    }

    private static function creditSummary(array $node,float $directChildCredit,float $directMemberCredit,float $unassignedCredit,float $unassignedNet): array
    {
        $granted=max(0,(float)($node['credit_limit']??0));
        $available=(float)($node['balance']??0);
        $allocated=(string)($node['level']??'')==='agent'?$directMemberCredit:$directChildCredit;
        $unassignedChange=$unassignedNet-$unassignedCredit;
        $notice=$granted<=0.000001?'上级尚未分配额度，当前不能向下级或会员分配分数':'';
        return [
            'granted_credit'=>number_format($granted,2,'.',''),
            'current_available_balance'=>number_format($available,2,'.',''),
            'direct_child_credit'=>number_format($directChildCredit,2,'.',''),
            'direct_member_credit'=>number_format($directMemberCredit,2,'.',''),
            'unassigned_member_credit'=>number_format($unassignedCredit,2,'.',''),
            'unassigned_member_net_score'=>number_format($unassignedNet,2,'.',''),
            'unassigned_member_settlement_change'=>number_format($unassignedChange,2,'.',''),
            'credit_unallocated'=>$granted<=0.000001,
            'credit_notice'=>$notice,
            // Compatibility aliases for older clients.
            'total_credit'=>number_format($granted,2,'.',''),
            'allocated_credit'=>number_format($allocated,2,'.',''),
            'available_credit'=>number_format($available,2,'.',''),
        ];
    }

    private static function emptyCreditSummary(): array
    {
        return self::creditSummary(['level'=>'','credit_limit'=>0,'balance'=>0],0.0,0.0,0.0,0.0);
    }
}
