<?php
declare(strict_types=1);
/**
 * KPS report-only regenerator v2 — targets the 总监 (director org 38) DISPLAYED P&L.
 *
 * Per user: 会员总投 ~15,000,000/day; middle levels computed by their 占成 (the reader
 * does this from ledger_json); 总监 final 盈亏 (= aggregated agent_profit at the root):
 *   2026-06 = -3,000,000 ; 2026-07 = -500,000 ; 2026-08 = +500,000 ; 2026-09(1..20) = +800,000
 *
 * The report reader recomputes money via OrganizationHierarchy::shareEdges + shareRowMetrics
 * from amount + win_amount + the per-org snapshot in ledger_json. The real ledger format is
 * [{organization_id, level, share_rate, booked}] with NO rate_mode (=> legacy 'edge' mode:
 * each configured share_rate is a fraction of the residual arriving at the node). We mirror
 * that exactly (raw configured share_rates per the robot's real chain).
 *
 * Director agent_profit is LINEAR per row in (amount, win); with house_r = beta*amount_r we
 * solve beta per month from two evaluated endpoints (win=0 and win=amount) using the REAL
 * classes, then set win_r accordingly (mild variance + exact residual correction on one row).
 *
 * Usage:  php robot_report2.php         # dry-run (compute + verify, no writes)
 *         php robot_report2.php --go     # delete site-15 rows in 6/1..9/20 and insert
 */

use think\facade\Db;
use app\service\OrganizationHierarchy;

ini_set('memory_limit','2048M');
date_default_timezone_set('PRC');
require __DIR__.'/vendor/autoload.php';
$app = new think\App(); $app->initialize();

const SITE=15; const TENANT=1; const WATER=0.085; const CAP=100.0;
const DAILY_TOTAL=15000000.0; const EPOCH='2026-06-01'; const DIR_ORG=38;
$MONTHS=[
  '2026-06'=>['d0'=>1,'d1'=>30,'target'=>-3000000.0],
  '2026-07'=>['d0'=>1,'d1'=>31,'target'=> -500000.0],
  '2026-08'=>['d0'=>1,'d1'=>31,'target'=>  500000.0],
  '2026-09'=>['d0'=>1,'d1'=>20,'target'=>  800000.0],
];
$GO = in_array('--go',$argv,true);

// ---- hierarchy maps ----
$nodeLevels=[]; $nodeParents=[];
foreach(Db::name('organization_nodes')->where('site_id',SITE)->field('id,level,parent_id')->select()->toArray() as $n){
  $nodeLevels[(int)$n['id']]=(string)$n['level']; $nodeParents[(int)$n['id']]=(int)$n['parent_id'];
}
$viewerIsRoot = (($nodeParents[DIR_ORG]??1)===0);
if(!$viewerIsRoot){ fwrite(STDERR,"director ".DIR_ORG." is not root\n"); exit(1); }

// ---- robots ----
$robots = Db::query(
  "SELECT ra.user_id, su.organization_id AS org, (su.balance+su.credit_balance) AS principal
   FROM robot_accounts ra JOIN site_users su ON su.id=ra.user_id
   WHERE ra.site_id=? AND ra.converted_at IS NULL ORDER BY ra.user_id",[SITE]);
if(!$robots){ fwrite(STDERR,"no robots\n"); exit(1); }
$sumP=0.0; foreach($robots as $r)$sumP+=(float)$r['principal'];

// ---- per-org ledger snapshot (raw configured share_rates, edge mode, matches real) ----
$LED=[]; $SNAP=[];
function buildOrg(int $org): void {
  global $LED,$SNAP;
  if(isset($LED[$org]))return;
  $entries=[]; $snap=[]; $nid=$org; $seen=[];
  while($nid>0 && !isset($seen[$nid])){ $seen[$nid]=1;
    $n=Db::query("SELECT id,parent_id,level FROM organization_nodes WHERE id=? AND deleted_at IS NULL",[$nid]);
    if(!$n)break; $n=$n[0];
    $sh=Db::query("SELECT share_rate FROM organization_profit_shares WHERE child_organization_id=? AND parent_organization_id=? AND status=1 LIMIT 1",[(int)$n['id'],(int)$n['parent_id']]);
    $rate=$sh?(float)$sh[0]['share_rate']:0.0;
    $entries[]=['organization_id'=>(int)$n['id'],'level'=>(string)$n['level'],'share_rate'=>$rate,'booked'=>0.0];
    $snap[(int)$n['id']]=['level'=>(string)$n['level'],'rate'=>max(0.0,min(CAP,$rate))/100.0,'mode'=>'edge'];
    $nid=(int)$n['parent_id'];
  }
  $LED[$org]=json_encode($entries,JSON_UNESCAPED_UNICODE);
  $SNAP[$org]=$snap;
}
foreach($robots as $r) buildOrg((int)$r['org']);

// director agent_profit for one row (uses real reader classes)
$cc=[];
function dirAP(float $amount,float $win,int $org): float {
  global $SNAP,$nodeLevels,$nodeParents,$cc,$viewerIsRoot;
  $edges=OrganizationHierarchy::shareEdges(SITE,$org,$cc,$SNAP[$org],$nodeLevels,$nodeParents,CAP,0.0,$org);
  $m=OrganizationHierarchy::shareRowMetrics($amount,$win-$amount,WATER,$edges,DIR_ORG,$viewerIsRoot);
  return (float)$m['agent_profit'];
}

function h01(string $s): float { return (crc32($s)%1000000)/1000000.0; }
function pad3(int $n): string { return sprintf('%03d',$n); }
function daysSince(string $ymd): int { return (int)((strtotime($ymd)-strtotime(EPOCH))/86400); }

printf("robots=%d Σprincipal=%.0f daily_total=%.0f director=%d\n",count($robots),$sumP,DAILY_TOTAL,DIR_ORG);

if($GO){
  echo "\n*** --go: deleting site-15 report_member_issue in 2026-06-01..2026-09-20 ***\n";
  $del=Db::name('report_member_issue')->where('site_id',SITE)->where('day','>=','2026-06-01')->where('day','<=','2026-09-20')->delete();
  echo "  deleted $del rows\n";
}

$now=date('Y-m-d H:i:s');
$batch=[];
function flushBatch(array &$b): void { if($b){ Db::name('report_member_issue')->insertAll($b); $b=[]; } }

$grand=['amt'=>0.0,'win'=>0.0,'rows'=>0,'ap'=>0.0];
foreach($MONTHS as $ym=>$mc){
  [$yy,$mm]=array_map('intval',explode('-',$ym));
  $rows=[];
  for($d=$mc['d0'];$d<=$mc['d1'];$d++){
    $ymd=sprintf('%04d-%02d-%02d',$yy,$mm,$d); $off=daysSince($ymd);
    foreach($robots as $rb){
      $uid=(int)$rb['user_id']; $org=(int)$rb['org'];
      $turn=DAILY_TOTAL*((float)$rb['principal']/$sumP);
      $fu=0.5+((crc32($uid.'-'.$off.'s')%2001)-1000)/10000.0; // 0.40..0.60
      foreach([[0,$fu],[1,1.0-$fu]] as [$lot,$frac]){
        $rows[]=['uid'=>$uid,'org'=>$org,'lot'=>$lot,'off'=>$off,'ymd'=>$ymd,'amount'=>round($turn*$frac,2)];
      }
    }
  }
  // endpoints for beta solve (director agent_profit is linear in global beta where house=beta*amount)
  $P0=0.0;$P1=0.0; foreach($rows as $r){ $P0+=dirAP($r['amount'],0.0,$r['org']); $P1+=dirAP($r['amount'],$r['amount'],$r['org']); }
  $beta = ($P0-$P1)!=0.0 ? ($mc['target']-$P1)/($P0-$P1) : 0.0;

  // assign win with mild variance around uniform beta
  foreach($rows as &$r){
    $v=(h01($r['uid'].'-'.$r['off'].'-'.$r['lot'].'w')-0.5)*2.0*0.12; // -0.12..0.12
    $r['win']=max(0.0,round($r['amount']*(1.0-$beta)*(1.0+$v),2));
  } unset($r);

  // exact residual correction on the highest-sensitivity row (director-heavy chain, big amount)
  $A=0.0; foreach($rows as $r)$A+=dirAP($r['amount'],$r['win'],$r['org']);
  $delta=$mc['target']-$A;
  // pick correction row: max amount among org 44 (pass-through ~1.0)
  $ci=-1;$cmax=-1.0; foreach($rows as $i=>$r){ if($r['org']===44 && $r['amount']>$cmax){$cmax=$r['amount'];$ci=$i;} }
  if($ci<0){ foreach($rows as $i=>$r){ if($r['amount']>$cmax){$cmax=$r['amount'];$ci=$i;} } }
  // measure local sensitivity c = -d(agent_profit)/d(win) on that row
  $w0=$rows[$ci]['win']; $ap_a=dirAP($rows[$ci]['amount'],$w0,$rows[$ci]['org']); $ap_b=dirAP($rows[$ci]['amount'],$w0+1000.0,$rows[$ci]['org']);
  $c=($ap_a-$ap_b)/1000.0; // agent_profit decreases as win rises => c>0
  if(abs($c)>1e-9){ $rows[$ci]['win']=max(0.0,round($w0 - $delta/$c,2)); }

  // verify
  $A2=0.0;$sumAmt=0.0;$sumWin=0.0; foreach($rows as $r){ $A2+=dirAP($r['amount'],$r['win'],$r['org']); $sumAmt+=$r['amount']; $sumWin+=$r['win']; }
  printf("%s d%02d-%02d rows=%d Σamt=%.0f Σwin=%.0f dealer=%+.0f  beta=%.5f  director agent_profit=%+.2f (target %+.0f, err %+.2f)\n",
    $ym,$mc['d0'],$mc['d1'],count($rows),$sumAmt,$sumWin,$sumAmt-$sumWin,$beta,$A2,$mc['target'],$A2-$mc['target']);

  if($GO){
    foreach($rows as $r){
      $issue=$r['lot']===0?('2026'.pad3(142+$r['off'])):('26'.pad3(142+$r['off']));
      $ln=$r['lot']===0?'福彩3D':'排列三';
      $dc=max(1,(int)round($r['amount']/2500.0)); $nc=$dc*8;
      $min=crc32($r['uid'].'-'.$r['off'].'-'.$r['lot'].'m')%1440;
      $placed=sprintf('%s %02d:%02d:00',$r['ymd'],intdiv($min,60),$min%60);
      $batch[]=[
        'tenant_id'=>TENANT,'site_id'=>SITE,'user_id'=>$r['uid'],'issue_no'=>$issue,'lottery_name'=>$ln,
        'day'=>$r['ymd'],'settled'=>1,'detail_count'=>$dc,'number_count'=>$nc,
        'amount'=>$r['amount'],'win_amount'=>$r['win'],'rebate'=>0,'intercepted'=>0,
        'placed_at'=>$placed,'ledger_json'=>$LED[$r['org']],'updated_at'=>$now,
      ];
      if(count($batch)>=400) flushBatch($batch);
    }
    flushBatch($batch);
  }
  $grand['amt']+=$sumAmt;$grand['win']+=$sumWin;$grand['rows']+=count($rows);$grand['ap']+=$A2;
  $rows=null; unset($rows); gc_collect_cycles();
}
printf("\n==== GRAND ==== rows=%d Σamt=%.0f Σwin=%.0f dealer=%+.0f  Σdirector agent_profit=%+.2f\n",
  $grand['rows'],$grand['amt'],$grand['win'],$grand['amt']-$grand['win'],$grand['ap']);
if($GO){
  $cnt=(int)Db::name('report_member_issue')->where('site_id',SITE)->count();
  echo "inserted; report_member_issue site-15 now has $cnt rows.\n";
  echo "WARNING: do NOT run `php think report:materialize` (would rebuild today+yesterday from raw tables).\n";
  echo "DONE.\n";
} else { echo "\n(dry-run — no writes; add --go)\n"; }
