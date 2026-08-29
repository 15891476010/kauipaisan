<?php
declare(strict_types=1);

require dirname(__DIR__, 1).'/vendor/autoload.php';
$app = new think\App(dirname(__DIR__, 1));
$app->initialize();

use app\service\CreditLedger;
use think\facade\Db;

$siteId = 15; $userId = 30;
$settled = [233 => ['detail'=>206,'submission'=>94,'old_win'=>192000.00,'new_win'=>276000.00], 240 => ['detail'=>217,'submission'=>100,'old_win'=>960.00,'new_win'=>1800.00]];
$pairOnly = [162 => ['detail'=>125,'odds'=>16.000], 163 => ['detail'=>126,'odds'=>16.000]];
$pending = [246 => ['detail'=>223,'submission'=>106]];

Db::transaction(function () use ($siteId,$userId,$settled,$pairOnly,$pending): void {
    $totalDelta = 0.0; $records = [];
    foreach ($settled as $recordId=>$expected) {
        $record=Db::name('bet_records')->where('id',$recordId)->where('site_id',$siteId)->where('user_id',$userId)->lock(true)->find();
        $detail=Db::name('bet_details')->where('id',$expected['detail'])->where('bet_record_id',$recordId)->lock(true)->find();
        if (!is_array($record)||!is_array($detail)||($record['submission_id']??null)!==$expected['submission']||(float)$record['win_amount']!==$expected['old_win']||(string)$record['status']!=='won') throw new RuntimeException('已结算目标校验失败：'.$recordId);
        $records[$recordId]=['record'=>$record,'detail'=>$detail,'expected'=>$expected]; $totalDelta += $expected['new_win']-$expected['old_win'];
    }
    foreach ($pairOnly as $recordId=>$expected) {
        $detail=Db::name('bet_details')->where('id',$expected['detail'])->where('bet_record_id',$recordId)->lock(true)->find();
        if (!is_array($detail)||(float)$detail['odds']!==$expected['odds']||!str_contains((string)$detail['source_text'],'双飞')) throw new RuntimeException('对子赔率目标校验失败：'.$recordId);
    }
    foreach ($pending as $recordId=>$expected) {
        $record=Db::name('bet_records')->where('id',$recordId)->where('site_id',$siteId)->where('user_id',$userId)->lock(true)->find();
        $detail=Db::name('bet_details')->where('id',$expected['detail'])->where('bet_record_id',$recordId)->lock(true)->find();
        if (!is_array($record)||!is_array($detail)||(string)$record['status']!=='pending'||(float)$detail['odds']!==16.000) throw new RuntimeException('待开奖目标校验失败：'.$recordId);
    }

    foreach ($settled as $recordId=>$expected) {
        $detail=$records[$recordId]['detail']; $delta=$expected['new_win']-$expected['old_win'];
        if ($recordId===233) {
            Db::name('bet_details')->where('id',206)->update(['number_text'=>'037','amount'=>'6000.00','odds'=>'16.000','win_amount'=>'96000.00','source_text'=>'37 双飞 福','status'=>'won']);
            Db::name('user_stop_drops')->where('bet_detail_id',206)->update(['number_text'=>'037','play_type'=>'双飞','original_amount'=>'6000.00','actual_amount'=>'6000.00','original_odds'=>'16.000','actual_odds'=>'16.000','source_text'=>'37 双飞 福']);
            $newId=(int)Db::name('bet_details')->insertGetId(['tenant_id'=>1,'site_id'=>$siteId,'user_id'=>$userId,'bet_record_id'=>233,'issue_no'=>'2608291356','number_text'=>'077','category'=>'福','amount'=>'6000.00','odds'=>'30.000','win_amount'=>'180000.00','rebate'=>'0.00','status'=>'won','placed_at'=>$detail['placed_at'],'source_text'=>'77 对子 福']);
            Db::name('user_stop_drops')->insert(['tenant_id'=>1,'site_id'=>$siteId,'user_id'=>$userId,'bet_detail_id'=>$newId,'lottery'=>'福彩3D','issue_no'=>'2608291356','number_text'=>'077','play_type'=>'对子','stop_type'=>'none','original_amount'=>'6000.00','actual_amount'=>'6000.00','stop_amount'=>'0.00','original_odds'=>'30.000','actual_odds'=>'30.000','drop_odds'=>'0.000','source_text'=>'77 对子 福','placed_at'=>$detail['placed_at'],'created_at'=>$detail['placed_at']]);
        } else {
            Db::name('bet_details')->where('id',217)->update(['number_text'=>'77对','odds'=>'30.000','win_amount'=>'1800.00','source_text'=>'77 对子 福','status'=>'won']);
            Db::name('user_stop_drops')->where('bet_detail_id',217)->update(['number_text'=>'77对','play_type'=>'对子','original_odds'=>'30.000','actual_odds'=>'30.000','source_text'=>'77 对子 福']);
        }
        Db::name('bet_records')->where('id',$recordId)->update(['win_amount'=>number_format($expected['new_win'],2,'.',''),'status'=>'won']);
        Db::name('bet_submissions')->where('id',$expected['submission'])->update(['win_amount'=>number_format($expected['new_win'],2,'.',''),'status'=>'won']);
        $record=$records[$recordId]['record'];
        $user=Db::name('site_users')->where('id',$userId)->where('site_id',$siteId)->lock(true)->find();
        $before=(float)$user['balance']; $after=round($before+$delta,2); Db::name('site_users')->where('id',$userId)->update(['balance'=>number_format($after,2,'.',''),'updated_at'=>date('Y-m-d H:i:s')]);
        CreditLedger::write(['tenant_id'=>1,'site_id'=>$siteId],null,'user',$userId,$userId,$recordId,null,(string)$record['issue_no'],$delta,$before,$after,'结算更正：双飞重复号码按对子赔率','manual_adjustment');
        $orgRows=Db::name('organization_credit_ledger')->where('related_bet_record_id',$recordId)->where('account_type','organization')->where('direction','out')->where('reason','本期投注亏损承担')->select()->toArray(); $sum=array_sum(array_map(static fn(array $r):float=>(float)$r['amount'],$orgRows)); $left=$delta;
        foreach($orgRows as $i=>$row){$part=$i===count($orgRows)-1?$left:round($delta*(float)$row['amount']/$sum,2);$left=round($left-$part,2);$node=Db::name('organization_nodes')->where('id',(int)$row['account_id'])->lock(true)->find();$nb=(float)$node['balance'];$na=round($nb-$part,2);Db::name('organization_nodes')->where('id',(int)$row['account_id'])->update(['balance'=>number_format($na,2,'.',''),'updated_at'=>date('Y-m-d H:i:s')]);CreditLedger::write(['tenant_id'=>1,'site_id'=>$siteId],(int)$row['account_id'],'organization',(int)$row['account_id'],$userId,$recordId,null,(string)$record['issue_no'],-$part,$nb,$na,'结算更正：双飞重复号码按对子赔率','manual_adjustment');}
        $bill=Db::name('bills')->where('site_id',$siteId)->where('user_id',$userId)->where('bill_date',substr((string)$record['placed_at'],0,10))->lock(true)->find();if($bill)Db::name('bills')->where('id',$bill['id'])->update(['win_amount'=>number_format((float)$bill['win_amount']+$delta,2,'.',''),'profit'=>number_format((float)$bill['profit']+$delta,2,'.','')]);
    }
    foreach($pairOnly as $recordId=>$expected){$detailId=$expected['detail'];$label=$recordId===163?'体':'福';Db::name('bet_details')->where('id',$detailId)->update(['number_text'=>'066','odds'=>'30.000','source_text'=>'66 对子 '.$label,'status'=>'unwon']);Db::name('user_stop_drops')->where('bet_detail_id',$detailId)->update(['play_type'=>'对子','original_odds'=>'30.000','actual_odds'=>'30.000','source_text'=>'66 对子 '.$label]);}
    foreach($pending as $recordId=>$expected){$detail=Db::name('bet_details')->where('id',$expected['detail'])->find();Db::name('bet_details')->where('id',$expected['detail'])->update(['number_text'=>'037','amount'=>'6000.00','odds'=>'16.000','source_text'=>'37 双飞 福']);Db::name('user_stop_drops')->where('bet_detail_id',$expected['detail'])->update(['number_text'=>'037','play_type'=>'双飞','original_amount'=>'6000.00','actual_amount'=>'6000.00','original_odds'=>'16.000','actual_odds'=>'16.000','source_text'=>'37 双飞 福']);$newId=(int)Db::name('bet_details')->insertGetId(['tenant_id'=>1,'site_id'=>$siteId,'user_id'=>$userId,'bet_record_id'=>$recordId,'issue_no'=>$detail['issue_no'],'number_text'=>'077','category'=>'福','amount'=>'6000.00','odds'=>'30.000','win_amount'=>'0.00','rebate'=>'0.00','status'=>'pending','placed_at'=>$detail['placed_at'],'source_text'=>'77 对子 福']);Db::name('user_stop_drops')->insert(['tenant_id'=>1,'site_id'=>$siteId,'user_id'=>$userId,'bet_detail_id'=>$newId,'lottery'=>'福彩3D','issue_no'=>$detail['issue_no'],'number_text'=>'077','play_type'=>'对子','stop_type'=>'none','original_amount'=>'6000.00','actual_amount'=>'6000.00','stop_amount'=>'0.00','original_odds'=>'30.000','actual_odds'=>'30.000','drop_odds'=>'0.000','source_text'=>'77 对子 福','placed_at'=>$detail['placed_at'],'created_at'=>$detail['placed_at']]);}
});
echo "pair odds repair completed\n";
