<?php
declare(strict_types=1);
// Independent verify: read persisted report_member_issue from DB, compute director(38)
// displayed agent_profit per month exactly as the reader does. Also show middle-level splits.
use think\facade\Db;
use app\service\OrganizationHierarchy;
require __DIR__.'/vendor/autoload.php';
$app=new think\App();$app->initialize();
const SITE=15; const WATER=0.085; const CAP=100.0; const DIR=38;

$nodeLevels=[];$nodeParents=[];
foreach(Db::name('organization_nodes')->where('site_id',SITE)->field('id,level,parent_id')->select()->toArray() as $n){
  $nodeLevels[(int)$n['id']]=(string)$n['level'];$nodeParents[(int)$n['id']]=(int)$n['parent_id'];
}
$uOrg=[];foreach(Db::name('site_users')->where('site_id',SITE)->field('id,organization_id')->select()->toArray() as $u)$uOrg[(int)$u['id']]=(int)$u['organization_id'];
$viewerIsRoot=(($nodeParents[DIR]??1)===0);$cc=[];
$MONTHS=['2026-06'=>['2026-06-01','2026-06-30',-3000000],'2026-07'=>['2026-07-01','2026-07-31',-500000],
         '2026-08'=>['2026-08-01','2026-08-31',500000],'2026-09'=>['2026-09-01','2026-09-20',800000]];

foreach($MONTHS as $ym=>[$from,$to,$target]){
  $rows=Db::name('report_member_issue')->where('site_id',SITE)->where('day','>=',$from)->where('day','<=',$to)
    ->field('user_id,amount,win_amount,rebate,ledger_json,settled')->select()->toArray();
  $amt=0.0;$win=0.0;$ap=0.0;$sp=0.0;$lvl=[];
  foreach($rows as $r){
    $amount=(float)$r['amount'];$w=(float)$r['win_amount'];$mp=$w+(float)$r['rebate']-$amount;
    $led=json_decode((string)$r['ledger_json'],true)?:[];$snap=[];$lineOrg=$uOrg[(int)$r['user_id']]??0;
    foreach($led as $e){$id=(int)($e['organization_id']??0);if($id<=0||isset($snap[$id]))continue;
      $lv=(string)($e['level']??'');if($lv===''&&$id>0)$lv=$nodeLevels[$id]??'';if($lv==='')continue;
      $snap[$id]=['level'=>$lv,'rate'=>max(0,min(CAP,(float)($e['share_rate']??0)))/100.0,'mode'=>(string)($e['rate_mode']??'edge')];}
    $edges=OrganizationHierarchy::shareEdges(SITE,$lineOrg,$cc,$snap,$nodeLevels,$nodeParents,CAP,0.0,$lineOrg);
    $m=OrganizationHierarchy::shareRowMetrics($amount,$mp,WATER,$edges,DIR,$viewerIsRoot);
    $amt+=$amount;$win+=$w;$ap+=(float)$m['agent_profit'];$sp+=(float)$m['share_profit'];
    foreach((array)($m['levels']??[]) as $lk=>$lv2){ $lvl[$lk]=($lvl[$lk]??0)+(float)($lv2['profit']??0); }
  }
  printf("%s rows=%d Σamt=%.0f Σwin=%.0f 明水=%.0f | 总监盈亏(agent_profit)=%+.0f (target %+d, err %+0.0f)  占成盈亏=%+.0f\n",
    $ym,count($rows),$amt,$win,$amt*WATER,$ap,$target,$ap-$target,$sp);
  foreach($lvl as $lk=>$v) printf("      %-18s 盈亏=%+.0f\n",$lk,$v);
}
