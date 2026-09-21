<?php
declare(strict_types=1);
// Mirror AgentReport::rows()+aggregate() EXACTLY for viewer=director(38), June..Sept, from DB.
use think\facade\Db;
use app\service\OrganizationHierarchy;
require __DIR__.'/vendor/autoload.php';
$app=new think\App();$app->initialize();
const SITE=15; const DIR=38;

// site settings exactly as controller reads them
$settings=Db::name('sites')->where('id',SITE)->value('settings');
$settings=is_string($settings)?json_decode($settings,true):(is_array($settings)?$settings:[]);
$waterRate=max(0,min(1,(float)($settings['water_rate']??$settings['dark_water_rate']??0.085)));
$siteCap=max(0,min(100,(float)($settings['max_profit_share_rate']??100)));
printf("site water_rate=%.5f  siteCap=%.2f\n",$waterRate,$siteCap);

$nodeLevels=[];$nodeParents=[];
foreach(Db::name('organization_nodes')->where('site_id',SITE)->field('id,level,parent_id')->select()->toArray() as $n){
  $nodeLevels[(int)$n['id']]=(string)$n['level'];$nodeParents[(int)$n['id']]=(int)$n['parent_id'];
}
$viewerIsRoot=(($nodeParents[DIR]??1)===0);
$chainCache=[];

$MONTHS=['2026-06'=>['2026-06-01','2026-06-30'],'2026-07'=>['2026-07-01','2026-07-31'],
         '2026-08'=>['2026-08-01','2026-08-31'],'2026-09'=>['2026-09-01','2026-09-20']];

foreach($MONTHS as $ym=>[$from,$to]){
  // materializedRows(): join site_users for organization_id
  $rows=Db::name('report_member_issue')->alias('m')
    ->join('site_users u','u.id=m.user_id AND u.site_id=m.site_id')
    ->where('m.site_id',SITE)->whereNull('u.deleted_at')
    ->where('m.day','>=',$from)->where('m.day','<=',$to)
    ->field('m.user_id,u.organization_id,m.amount,m.win_amount,m.rebate,m.settled,m.ledger_json')
    ->select()->toArray();
  $T=['amount'=>0.0,'member_profit'=>0.0,'share_amount'=>0.0,'share_profit'=>0.0,'agent_water'=>0.0,
      'agent_profit'=>0.0,'viewer_amount'=>0.0,'platform_amount'=>0.0,'platform_profit'=>0.0,'offline_water'=>0.0];
  $lvl=[];
  foreach($rows as $row){
    $amount=(float)$row['amount'];$win=(float)$row['win_amount'];$rebate=(float)$row['rebate'];
    $memberProfit=$win+$rebate-$amount;
    $led=$row['ledger_json']!==''?json_decode((string)$row['ledger_json'],true):null;
    $snapshot=null;$lineOrgId=0;
    if((int)($row['settled']??0)===1&&!empty($led)&&is_array($led)){
      $snapshot=[];
      foreach($led as $entry){
        $id=(int)($entry['organization_id']??0);
        if($lineOrgId===0)$lineOrgId=(int)($entry['line_org']??0);
        $level=(string)($entry['level']??'');
        if($level===''&&$id>0)$level=$nodeLevels[$id]??'';
        if($level==='')continue;
        $snapshot[$id]=['level'=>$level,'rate'=>max(0,min($siteCap,(float)($entry['share_rate']??0)))/100.0,'mode'=>(string)($entry['rate_mode']??'edge')];
      }
    }
    $edges=OrganizationHierarchy::shareEdges(SITE,(int)($row['organization_id']??0),$chainCache,$snapshot,$nodeLevels,$nodeParents,$siteCap,0.0,$lineOrgId);
    $m=OrganizationHierarchy::shareRowMetrics($amount,$memberProfit,$waterRate,$edges,DIR,$viewerIsRoot);
    $T['amount']+=$amount;$T['member_profit']+=$memberProfit;
    $T['share_amount']+=(float)$m['share_amount'];$T['share_profit']+=(float)$m['share_profit'];
    $T['agent_water']+=(float)$m['agent_water'];$T['agent_profit']+=(float)$m['agent_profit'];
    $T['viewer_amount']+=(float)$m['viewer_amount'];$T['offline_water']+=(float)$m['offline_water'];
    $T['platform_amount']+=(float)$m['platform_amount'];$T['platform_profit']+=(float)$m['platform_profit'];
    foreach((array)($m['levels']??[]) as $lk=>$lv){
      if(!isset($lvl[$lk]))$lvl[$lk]=['amount'=>0.0,'water'=>0.0,'profit'=>0.0,'share_amount'=>0.0,'share_profit'=>0.0];
      foreach(['amount','water','profit','share_amount','share_profit'] as $k)$lvl[$lk][$k]+=(float)($lv[$k]??0);
    }
  }
  printf("\n=== %s  rows=%d ===\n",$ym,count($rows));
  printf("  投注 amount        = %+.2f\n",$T['amount']);
  printf("  会员盈亏 member_pft = %+.2f\n",$T['member_profit']);
  printf("  总监 viewer_amount = %+.2f  (承接总投)\n",$T['viewer_amount']);
  printf("  总监 share_amount  = %+.2f\n",$T['share_amount']);
  printf("  总监 share_profit  = %+.2f  (占成盈亏)\n",$T['share_profit']);
  printf("  总监 agent_water   = %+.2f  (赚水)\n",$T['agent_water']);
  printf("  总监 offline_water = %+.2f\n",$T['offline_water']);
  printf("  总监 agent_profit  = %+.2f  <== 末列 盈亏\n",$T['agent_profit']);
  printf("  platform_amount    = %+.2f  platform_profit=%+.2f\n",$T['platform_amount'],$T['platform_profit']);
  foreach($lvl as $lk=>$v)
    printf("   [%-18s] 占成额=%+.0f 赚水=%+.0f 盈亏=%+.0f 占成盈亏=%+.0f\n",$lk,$v['amount'],$v['water'],$v['profit'],$v['share_profit']);
}
