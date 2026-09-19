<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class OrganizationHierarchy
{
    public const LEVELS = ['director','shareholder','small_shareholder','general_agent','agent'];
    public const LABELS = ['director'=>'总监','shareholder'=>'大股东','small_shareholder'=>'小股东','general_agent'=>'总代理','agent'=>'代理'];
    public const CHILD_LEVELS = [
        // A parent may create any lower level directly.  The relationship is
        // still a real parent/child link, so credit, permissions and reports
        // follow the selected direct parent rather than an inferred level.
        'director' => ['shareholder', 'small_shareholder', 'general_agent', 'agent'],
        'shareholder' => ['small_shareholder', 'general_agent', 'agent'],
        'small_shareholder' => ['general_agent', 'agent'],
        'general_agent' => ['agent'],
        'agent' => [],
    ];
    public const PERMISSIONS = [
        'route.overview'=>'路由：总货概览','route.ledger'=>'路由：贡献度/分类账','route.reports'=>'路由：报表','route.results'=>'路由：开奖号码',
        'route.organizations'=>'路由：组织架构','route.subordinates'=>'路由：会员/下级管理','route.intercept'=>'路由：拦货','route.logs'=>'路由：日志',
        'route.rules'=>'路由：规则说明','route.settings'=>'路由：业务设置','route.subaccounts'=>'路由：子账号',
        'overview'=>'数据概览','order_details'=>'总货明细','winning_details'=>'中奖明细','bet_details'=>'投注明细','refunds'=>'查看退码',
        'contribution'=>'贡献度','daily_ledger'=>'日分类账','monthly_ledger'=>'月分类账','daily_path'=>'日路径账','monthly_path'=>'月路径账',
        'reports'=>'报表','monthly_reports'=>'月报表','results'=>'开奖号码','subordinates'=>'下级管理',
        'interception_details'=>'拦货明细','interception_winning'=>'拦货中奖','interception_plate'=>'拦货盘面',
        'rules'=>'规则说明','settings'=>'业务设置','settings.update'=>'保存业务设置','logs'=>'日志','subaccounts'=>'子账号','organization.manage'=>'下级管理',
        'organization.create'=>'新增下级','organization.update'=>'修改下级','organization.delete'=>'删除下级',
        'member.create'=>'新增下级','member.update'=>'修改下级','subaccount.create'=>'新建子账号','subaccount.update'=>'修改子账号','subaccount.delete'=>'删除子账号',
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
        $items=AgentAuthorization::expandLegacyRoutes($items);
        $parentPermissions=AgentAuthorization::expandLegacyRoutes($parentPermissions);
        if (in_array('*',$parentPermissions,true)) return $items;
        return array_values(array_intersect($items,$parentPermissions));
    }

    public static function decodePermissions(mixed $value): array
    {
        if (is_array($value)) return AgentAuthorization::expandLegacyRoutes(array_values(array_map('strval',$value)));
        $decoded=is_string($value)?json_decode($value,true):null;
        return is_array($decoded)?AgentAuthorization::expandLegacyRoutes(array_values(array_map('strval',$decoded))):[];
    }

    public static function rootForSite(int $siteId): ?array
    {
        $query=Db::name('organization_nodes')->where('site_id',$siteId)->where('parent_id',0)->whereNull('deleted_at');
        return $query->where('level','director')->find() ?: null;
    }

    /**
     * Leaf-to-root organization chain with each node's active share rate
     * toward its parent attached as `share_rate`. Used by report and ledger
     * aggregation so both share the same chain definition.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function shareChain(int $siteId, int $organizationId): array
    {
        $chain=[];$current=$organizationId;$visited=[];
        while($current>0&&!in_array($current,$visited,true)) {
            $visited[]=$current;
            $node=Db::name('organization_nodes')->where('id',$current)->where('site_id',$siteId)->where('status',1)->whereNull('deleted_at')->find();
            if(!$node) break;
            $share=Db::name('organization_profit_shares')
                ->where('child_organization_id',(int)$node['id'])
                ->where('parent_organization_id',(int)$node['parent_id'])
                ->where('status',1)->find();
            $node['share_rate']=$share?(float)$share['share_rate']:0.0;
            $chain[]=$node;
            $current=(int)$node['parent_id'];
        }
        return $chain;
    }

    /**
     * Ordered leaf→root share edges for report math. Each edge carries the
     * node id, level, parent link and edge rate (a fraction of the residual
     * book arriving at that node — absolute share of the member turnover is
     * rate × Π(1−rates below)).
     *
     * With a settle-time ledger snapshot (entries keyed by organization id,
     * holding rate fractions and levels) the snapshot alone supplies the
     * chain — the member may have moved branches since, so the live tree is
     * NOT merged in (merging would double-count levels and inflate water).
     * $lineOrgId is the member's organization at settle time (ledger
     * metadata); it is prepended as a zero-rate leaf when it booked nothing,
     * so the level above still earns its offline water on the member book.
     *
     * @param array<int,array<int,array<string,mixed>>> $chainCache
     * @param array<int,array{rate:float,level:string}>|null $snapshot
     * @param array<int,string> $nodeLevels
     * @param array<int,int> $nodeParents
     * @return array<int,array{id:int,level:string,parent_id:int,rate:float}>
     */
    public static function shareEdges(int $siteId,int $memberOrgId,array &$chainCache,?array $snapshot,array $nodeLevels,array $nodeParents,float $siteCap,float $fallbackRate=0.0,int $lineOrgId=0): array
    {
        if($snapshot!==null){
            $order=array_flip(array_reverse(self::LEVELS));
            $edges=[];
            foreach($snapshot as $orgId=>$entry){
                $level=(string)($entry['level']??'');
                if($level==='')$level=(string)($nodeLevels[$orgId]??'');
                if($level==='')continue;
                $edges[]=['id'=>(int)$orgId,'level'=>$level,'parent_id'=>(int)($nodeParents[$orgId]??0),'rate'=>(float)$entry['rate']];
            }
            if($lineOrgId>0&&!isset($snapshot[$lineOrgId])){
                $edges[]=['id'=>$lineOrgId,'level'=>(string)($nodeLevels[$lineOrgId]??'agent'),'parent_id'=>(int)($nodeParents[$lineOrgId]??0),'rate'=>0.0];
            }
            usort($edges,static function(array $a,array $b) use($order): int{return($order[$a['level']]??99)<=>($order[$b['level']]??99);});
            return $edges;
        }
        $chain=$memberOrgId>0?($chainCache[$memberOrgId]??=self::shareChain($siteId,$memberOrgId)):[];
        if($chain===[]){
            $chain=$fallbackRate>0
                ?[['id'=>0,'parent_id'=>1,'level'=>'agent','share_rate'=>$fallbackRate]]
                :(($root=self::rootForSite($siteId))?[$root]:[]);
        }
        $edges=[];
        foreach($chain as $node){
            $edges[]=['id'=>(int)$node['id'],'level'=>(string)($node['level']??''),'parent_id'=>(int)($node['parent_id']??0),'rate'=>max(0,min($siteCap,(float)($node['share_rate']??0)))/100.0];
        }
        return $edges;
    }

    /**
     * Per-book share metrics for one member's aggregated turnover, computed
     * the way the reference system does: each level's occupied amount is its
     * edge rate × the residual book arriving at it; its share P/L is rate ×
     * the member net result − water rate × the occupied amount; a level's
     * income is the share its children booked plus offline water on their
     * attributed book. The top node (the boss) earns no offline water and
     * bears the water the subtree collects, so its water column is a cost.
     * The direct child's displayed profit uses the conservation identity:
     * −(viewer income + upline residual).
     *
     * @param array<int,array{id:int,level:string,parent_id:int,rate:float}> $edges leaf→root
     * @return array<string,mixed>
     */
    public static function shareRowMetrics(float $amount,float $memberProfit,float $waterRate,array $edges,int $viewerOrgId,bool $viewerIsRoot): array
    {
        $houseProfit=-$memberProfit;
        $n=count($edges);$levelData=[];$viewerIdx=-1;$childIdx=-1;$arr=1.0;
        foreach($edges as $i=>$edge){
            $attr=$amount*$arr;                                   // 承接总投：到达本级的剩余本金
            $shareAmount=$edge['rate']*$attr;                     // 占成金额 = 边率×承接额
            $shareProfit=$edge['rate']*$arr*$houseProfit-$waterRate*$shareAmount; // 占成盈亏 = 有效份额×净结果 − 水钱×占成金额
            // 离线反水：本级按自己的承接总投收水钱（下一级切完占成后上交的
            // 本金），叶子级贴着会员也收；顶端老板级不收——它是水钱承担方。
            $waterIncome=$i<$n-1?$waterRate*$attr:0.0;
            $income=($i>0?$levelData[$i-1]['share_profit']:0.0)+$waterIncome;
            $levelData[$i]=['level'=>$edge['level'],'attr'=>$attr,'share_amount'=>$shareAmount,'share_profit'=>$shareProfit,'water_income'=>$waterIncome,'profit'=>$income+$memberProfit*$arr,'arr'=>$arr];
            if((int)$edge['id']===$viewerOrgId)$viewerIdx=$i;
            if((int)$edge['parent_id']===$viewerOrgId)$childIdx=$i;
            $arr*=(1.0-$edge['rate']);
        }
        // Residual book escaping the whole chain lands on the platform.
        $platformAmount=$amount*$arr;$platformProfit=$houseProfit*$arr-$waterRate*$platformAmount;
        $shareAmount=0.0;$shareProfit=0.0;$offlineWater=0.0;$agentProfit=0.0;$uplineAmount=0.0;$uplineProfit=0.0;$viewerRate=0.0;
        if($viewerIdx>=0){
            $viewer=$levelData[$viewerIdx];
            $shareAmount=$viewer['share_amount'];$shareProfit=$viewer['share_profit'];$viewerRate=$edges[$viewerIdx]['rate'];
        } elseif($viewerIsRoot){
            // Snapshot chains may stop below the viewer; the residual
            // arriving above the listed edges is then the root's own book.
            $shareAmount=$platformAmount;$shareProfit=$platformProfit;
        }
        if($childIdx>=0)$agentProfit=$levelData[$childIdx]['share_profit'];
        $agentWater=0.0;
        if($viewerIsRoot){
            $subtreeWater=0.0;
            foreach($levelData as $i=>$ld){ if($viewerIdx>=0&&$i>=$viewerIdx)continue; $subtreeWater+=$ld['water_income']; }
            // The boss receives no offline rebate (it is the payer, not a
            // recipient) — the column stays 0 while the water cost the whole
            // subtree collects is borne inside its total P/L and reported as
            // negative 赚水.
            $agentWater=-$subtreeWater;
            $agentProfit+=$shareProfit+$agentWater;
        } else {
            // 本级离线反水 = 8.5%×本级承接总投（本级收到的本金）。
            $offlineWater=$viewerIdx>=0?$levelData[$viewerIdx]['water_income']:0.0;
            $agentWater=$offlineWater;
            $agentProfit+=$offlineWater;
            if($viewerIdx>=0){$uplineAmount=$amount*$levelData[$viewerIdx]['arr'];$uplineProfit=$houseProfit*$levelData[$viewerIdx]['arr']-$waterRate*$uplineAmount;}
        }
        // 守恒：直属下级行的盈亏 = −(本级收入 + 上级残余)。
        if($childIdx>=0)$levelData[$childIdx]['profit']=-($agentProfit+$uplineProfit);
        // Only levels strictly below the viewer appear as columns; the
        // viewer's own level lives in the self columns.
        $levels=[];
        foreach($levelData as $i=>$ld){
            if($ld['level']===''||($viewerIdx>=0&&$i>=$viewerIdx))continue;
            $levels[$ld['level']]=['amount'=>$ld['attr'],'water'=>$ld['water_income'],'profit'=>$ld['profit'],'share_amount'=>$ld['share_amount'],'share_profit'=>$ld['share_profit']];
        }
        return ['levels'=>$levels,'share_amount'=>$shareAmount,'share_profit'=>$shareProfit,'viewer_rate'=>$viewerRate,'viewer_idx'=>$viewerIdx,'offline_water'=>$offlineWater,'agent_water'=>$agentWater,'agent_profit'=>$agentProfit,'upline_amount'=>$uplineAmount,'upline_profit'=>$uplineProfit,'platform_amount'=>$platformAmount,'platform_profit'=>$platformProfit];
    }

    public static function accountContext(array $account): array
    {
        $node=Db::name('organization_nodes')->where('id',(int)$account['organization_id'])->where('site_id',(int)$account['site_id'])->where('status',1)->whereNull('deleted_at')->find();
        if (!$node) throw new \RuntimeException('当前组织已停用或删除');
        // 账号权限字段仅作历史兼容，不参与授权。所有管理员统一继承组织节点
        // 及 SaaS 当前站点/层级的权限配置。
        $permissions=self::effectivePermissions((int)$node['id']);
        return ['node'=>$node,'permissions'=>$permissions ?: []];
    }

    public static function effectivePermissions(int $organizationId, array $accountPermissions=[]): array
    {
        $node=Db::name('organization_nodes')->where('id',$organizationId)->whereNull('deleted_at')->find();
        if (!$node) return [];
        // 权限按“站点 + 当前层级”统一计算，不再沿组织链或账号字段做额外裁剪。
        // 这样同一层级的所有管理员拥有完全一致的路由/按钮权限。
        return AgentAuthorization::sitePermissions((int)$node['site_id'],(string)$node['level']);
    }

    public static function subaccountPermissions(int $organizationId,mixed $permissions): array
    {
        return AgentAuthorization::subaccountPermissions($permissions,self::effectivePermissions($organizationId));
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

    public static function assertManageableNode(array $session, int $organizationId, bool $includeSelf = false): array
    {
        $rootId = (int)($session['organization_id'] ?? 0);
        $siteId = (int)($session['site_id'] ?? 0);
        $tenantId = (int)($session['tenant_id'] ?? 0);
        $root = $rootId > 0 ? Db::name('organization_nodes')->where('id', $rootId)
            ->where('site_id', $siteId)->where('tenant_id', $tenantId)->whereNull('deleted_at')->find() : null;
        if (!$root || empty($root['path']) || (!$includeSelf && $organizationId === $rootId)) {
            throw new \InvalidArgumentException('无权管理该组织，只能操作当前账号的下级');
        }
        $node = Db::name('organization_nodes')->where('id', $organizationId)
            ->where('site_id', $siteId)->where('tenant_id', $tenantId)
            ->whereLike('path', (string)$root['path'].'%')->whereNull('deleted_at')->find();
        if (!$node) throw new \InvalidArgumentException('无权管理该组织，只能操作当前账号的下级');
        return $node;
    }

    public static function managementPermissions(array $session): array
    {
        $root = self::assertManageableNode($session, (int)($session['organization_id'] ?? 0), true);
        $permissions = self::effectivePermissions((int)$root['id']);
        // The session can further restrict subaccounts; browsing a child must
        // never replace the operator's permissions with that child's permissions.
        return isset($session['permissions']) && is_array($session['permissions'])
            ? AgentAuthorization::intersect($session['permissions'], $permissions)
            : $permissions;
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
