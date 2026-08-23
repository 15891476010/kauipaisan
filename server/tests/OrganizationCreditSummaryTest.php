<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));
$app->initialize();

use app\service\OrganizationHierarchy;
use think\facade\Db;

function expectMoney(string $actual,float $expected,string $field,int $nodeId): void
{
    if(abs((float)$actual-$expected)>0.001)throw new RuntimeException("节点 {$nodeId} 的 {$field} 统计错误: {$actual} != {$expected}");
}

$nodes=Db::name('organization_nodes')->whereNull('deleted_at')->order('id')->select()->toArray();
if($nodes===[])throw new RuntimeException('缺少组织额度统计测试数据');
foreach($nodes as $node){
    $nodeId=(int)$node['id'];$summary=OrganizationHierarchy::nodeCreditSummary($nodeId);
    $childCredit=(float)Db::name('organization_nodes')->where('parent_id',$nodeId)->whereNull('deleted_at')->sum('credit_limit');
    $memberCredit=(float)Db::name('site_users')->where('organization_id',$nodeId)->whereNull('deleted_at')->sum('credit_balance');
    $isRoot=(string)$node['level']==='director'&&(int)$node['parent_id']===0;
    $unassignedCredit=0.0;$unassignedNet=0.0;
    if($isRoot){
        $unassigned=Db::name('site_users')->where('site_id',(int)$node['site_id'])->whereNull('organization_id')->whereNull('deleted_at');
        $unassignedCredit=(float)(clone $unassigned)->sum('credit_balance');
        $row=(clone $unassigned)->fieldRaw('COALESCE(SUM(credit_balance + balance),0) AS net_score')->find();
        $unassignedNet=(float)($row['net_score']??0);
    }
    expectMoney($summary['granted_credit'],(float)$node['credit_limit'],'granted_credit',$nodeId);
    expectMoney($summary['current_available_balance'],(float)$node['balance'],'current_available_balance',$nodeId);
    expectMoney($summary['direct_child_credit'],$childCredit,'direct_child_credit',$nodeId);
    expectMoney($summary['direct_member_credit'],$memberCredit,'direct_member_credit',$nodeId);
    expectMoney($summary['unassigned_member_credit'],$unassignedCredit,'unassigned_member_credit',$nodeId);
    expectMoney($summary['unassigned_member_net_score'],$unassignedNet,'unassigned_member_net_score',$nodeId);
    expectMoney($summary['unassigned_member_settlement_change'],$unassignedNet-$unassignedCredit,'unassigned_member_settlement_change',$nodeId);
    $legacyAllocated=(string)$node['level']==='agent'?$memberCredit:$childCredit;
    expectMoney($summary['allocated_credit'],$legacyAllocated,'allocated_credit compatibility alias',$nodeId);
    $shouldWarn=(float)$node['credit_limit']<=0.000001;
    if((bool)$summary['credit_unallocated']!==$shouldWarn)throw new RuntimeException("节点 {$nodeId} 的零额度提示状态错误");
    if($shouldWarn&&trim((string)$summary['credit_notice'])==='')throw new RuntimeException("节点 {$nodeId} 缺少上级未分配额度提示");
    if(!$isRoot&&((float)$summary['unassigned_member_credit']!==0.0||(float)$summary['unassigned_member_net_score']!==0.0))throw new RuntimeException("非根节点 {$nodeId} 串算了整站未归属会员");
}

echo "Organization credit summary scope tests passed for ".count($nodes)." nodes\n";
