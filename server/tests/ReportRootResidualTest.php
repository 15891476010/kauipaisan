<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use app\service\OrganizationHierarchy;

$check=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$same=static fn(float $a,float $b):bool=>abs($a-$b)<0.0001;

$amount=1000.0;$memberProfit=-600.0;$waterRate=0.085;
$edges=[
    ['id'=>303,'level'=>'agent','parent_id'=>302,'rate'=>0.0],
    ['id'=>302,'level'=>'general_agent','parent_id'=>52,'rate'=>0.5],
    // Stale historical value: the top director must take the remaining 50%.
    ['id'=>52,'level'=>'director','parent_id'=>0,'rate'=>0.2],
];

$legacyBefore=OrganizationHierarchy::shareRowMetrics($amount,$memberProfit,$waterRate,$edges,52,true);
$report=OrganizationHierarchy::reportRowMetrics($amount,$memberProfit,$waterRate,$edges,52,true);
$legacyAfter=OrganizationHierarchy::shareRowMetrics($amount,$memberProfit,$waterRate,$edges,52,true);

$check($legacyBefore===$legacyAfter,'报表修正不得改变旧结算/改单共享公式');
$check($same((float)$report['viewer_rate'],0.5),'总监应自动承接下级分配后剩余的50%');
$check($same((float)$report['share_amount'],500.0),'总监占成金额应为剩余的500');
$check($same((float)$report['platform_amount'],0.0),'最高总监承接后不应再有平台逃逸金额');
$agent=$report['levels']['agent']??null;
$check(is_array($agent),'代理层级结果缺失');
foreach(['amount','water','profit','share_amount','share_profit'] as $key)
    $check($same((float)($agent[$key]??-1),0.0),'0%代理的'.$key.'必须为0');

$zeroViewer=OrganizationHierarchy::reportRowMetrics($amount,$memberProfit,$waterRate,$edges,303,false);
foreach(['viewer_amount','share_amount','share_profit','offline_water','agent_water','agent_profit'] as $key)
    $check($same((float)($zeroViewer[$key]??-1),0.0),'0%当前层级的'.$key.'必须为0');

// If the director is omitted from an old settlement snapshot, the root viewer
// still owns all residual share above the listed lower levels.
$snapshotEdges=[
    ['id'=>303,'level'=>'agent','parent_id'=>302,'rate'=>0.0],
    ['id'=>302,'level'=>'general_agent','parent_id'=>52,'rate'=>0.5],
];
$snapshotReport=OrganizationHierarchy::reportRowMetrics($amount,$memberProfit,$waterRate,$snapshotEdges,52,true);
$check($same((float)$snapshotReport['share_amount'],500.0),'快照缺少总监边时，总监仍应承接剩余500');
$check($same((float)$snapshotReport['platform_amount'],0.0),'最高总监承接后平台剩余必须为0');

$fullChildSnapshot=[['id'=>303,'level'=>'agent','parent_id'=>52,'rate'=>1.0]];
$zeroRoot=OrganizationHierarchy::reportRowMetrics($amount,$memberProfit,$waterRate,$fullChildSnapshot,52,true);
foreach(['viewer_amount','share_amount','share_profit','offline_water','agent_water','agent_profit'] as $key)
    $check($same((float)($zeroRoot[$key]??-1),0.0),'下级已占100%时，0%总监的'.$key.'必须为0');

// Live rates consume the remaining book successively: 90% of 1000, then
// 90% of the remaining 100, leaving 10 for the top director.
$successiveEdges=[
    ['id'=>303,'level'=>'agent','parent_id'=>302,'rate'=>0.9],
    ['id'=>302,'level'=>'general_agent','parent_id'=>52,'rate'=>0.9],
    ['id'=>52,'level'=>'director','parent_id'=>0,'rate'=>0.2],
];
$agent90=OrganizationHierarchy::reportRowMetrics($amount,$memberProfit,$waterRate,$successiveEdges,303,false);
$general90=OrganizationHierarchy::reportRowMetrics($amount,$memberProfit,$waterRate,$successiveEdges,302,false);
$director90=OrganizationHierarchy::reportRowMetrics($amount,$memberProfit,$waterRate,$successiveEdges,52,true);
$check($same((float)$agent90['share_amount'],900.0),'第一级90%应占总投900');
$check($same((float)$general90['share_amount'],90.0),'第二级90%应占剩余100中的90');
$check($same((float)$director90['share_amount'],10.0),'总监应承接最后剩余10');

// Snapshot rates are already direct fractions and must remain 60% + 30% +
// residual 10%, rather than being cascaded into 60% + 12%.
$bookedEdges=[
    ['id'=>303,'level'=>'agent','parent_id'=>302,'rate'=>0.6,'mode'=>'direct'],
    ['id'=>302,'level'=>'general_agent','parent_id'=>52,'rate'=>0.3,'mode'=>'direct'],
    ['id'=>52,'level'=>'director','parent_id'=>0,'rate'=>0.2,'mode'=>'direct'],
];
$bookedGeneral=OrganizationHierarchy::reportRowMetrics($amount,$memberProfit,$waterRate,$bookedEdges,302,false);
$bookedDirector=OrganizationHierarchy::reportRowMetrics($amount,$memberProfit,$waterRate,$bookedEdges,52,true);
$check($same((float)$bookedGeneral['share_amount'],300.0),'历史快照30%不得再次乘剩余');
$check($same((float)$bookedDirector['share_amount'],100.0),'历史快照总监应承接已记账比例后的10%');

echo "ReportRootResidualTest passed: root owns residual; zero-share levels display zero\n";
