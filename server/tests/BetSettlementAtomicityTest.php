<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));
$app->initialize();

use app\service\BetSettlement;
use think\facade\Db;

function assertNear(float $actual,float $expected,string $message): void
{
    if(abs($actual-$expected)>0.001)throw new RuntimeException($message.": expected {$expected}, got {$actual}");
}

function createSettlementFixture(array $user,array $lottery,string $issue,?float $odds=3.0): array
{
    $now=date('Y-m-d H:i:s');
    $recordId=(int)Db::name('bet_records')->insertGetId([
        'tenant_id'=>(int)$user['tenant_id'],'site_id'=>(int)$user['site_id'],'user_id'=>(int)$user['id'],
        'issue_no'=>$issue,'source_text'=>'123直100元','formatted_text'=>'123直100元','bet_count'=>1,
        'amount'=>'100.00','win_amount'=>'0.00','status'=>'pending','sealed'=>1,'placed_at'=>$now,'created_at'=>$now,
    ]);
    $detailId=(int)Db::name('bet_details')->insertGetId([
        'tenant_id'=>(int)$user['tenant_id'],'site_id'=>(int)$user['site_id'],'user_id'=>(int)$user['id'],
        'bet_record_id'=>$recordId,'issue_no'=>$issue,'number_text'=>'123','category'=>'直选','amount'=>'100.00',
        'odds'=>$odds===null?null:number_format($odds,3,'.',''),'win_amount'=>'0.00','rebate'=>'0.00','status'=>'pending','placed_at'=>$now,'source_text'=>'123直100元',
    ]);
    Db::name('user_stop_drops')->insert([
        'tenant_id'=>(int)$user['tenant_id'],'site_id'=>(int)$user['site_id'],'user_id'=>(int)$user['id'],
        'bet_detail_id'=>$detailId,'lottery'=>(string)$lottery['name'],'issue_no'=>$issue,'number_text'=>'123','play_type'=>'直选',
        'stop_type'=>'none','original_amount'=>'100.00','actual_amount'=>'100.00','stop_amount'=>'0.00',
        'original_odds'=>$odds===null?null:number_format($odds,3,'.',''),'actual_odds'=>$odds===null?null:number_format($odds,3,'.',''),'drop_odds'=>'0.000',
        'source_text'=>'123直100元','placed_at'=>$now,'created_at'=>$now,
    ]);
    Db::name('site_users')->where('id',(int)$user['id'])->update(['used_balance'=>Db::raw('used_balance + 100.00')]);
    return [$recordId,$detailId];
}

$lottery=Db::name('lotteries')->where('status',1)->whereNull('deleted_at')->order('id')->find();
$user=Db::name('site_users')->whereNotNull('organization_id')->where('status',1)->whereNull('deleted_at')->order('id')->find();
$unassigned=Db::name('site_users')->whereNull('organization_id')->where('status',1)->whereNull('deleted_at')->order('id')->find();
$siteWithoutRoot=Db::name('sites')->alias('s')->leftJoin('organization_nodes n','n.site_id=s.id AND n.parent_id=0 AND n.deleted_at IS NULL')->whereNull('n.id')->field('s.id')->find();
if(!$lottery||!$user||!$unassigned||!$siteWithoutRoot)throw new RuntimeException('缺少结算原子性测试所需的基础数据');

$service=new BetSettlement();
Db::startTrans();
try{
    $issue='LOCKED-ODDS-'.bin2hex(random_bytes(4));
    $userBefore=Db::name('site_users')->where('id',(int)$user['id'])->find();
    [$recordId,$detailId]=createSettlementFixture($user,$lottery,$issue,3.0);
    Db::name('lottery_odds')->where('lottery_id',(int)$lottery['id'])->update(['status'=>0]);
    Db::name('lottery_odds_categories')->where('lottery_id',(int)$lottery['id'])->update(['status'=>0]);
    $result=$service->settleForHistory(['code'=>$issue,'numbers'=>'123'],$lottery);
    if($result!==['records'=>1,'won'=>1])throw new RuntimeException('锁定赔率注单未正常结算');
    $record=Db::name('bet_records')->where('id',$recordId)->find();
    $detail=Db::name('bet_details')->where('id',$detailId)->find();
    $stop=Db::name('user_stop_drops')->where('bet_detail_id',$detailId)->find();
    $userAfter=Db::name('site_users')->where('id',(int)$user['id'])->find();
    assertNear((float)$record['win_amount'],300.0,'主单赔付未使用下注时赔率');
    assertNear((float)$detail['odds'],3.0,'结算覆盖了下注时锁定赔率');
    assertNear((float)$stop['actual_odds'],3.0,'结算覆盖了停押降水锁定赔率');
    assertNear((float)$userAfter['balance'],(float)$userBefore['balance']+200.0,'用户结算余额错误');
    $ledgerCount=(int)Db::name('organization_credit_ledger')->where('related_bet_record_id',$recordId)->count();
    $second=$service->settleForHistory(['code'=>$issue,'numbers'=>'999'],$lottery);
    if($second!==['records'=>0,'won'=>0])throw new RuntimeException('重复结算没有被幂等拦截');
    if((int)Db::name('organization_credit_ledger')->where('related_bet_record_id',$recordId)->count()!==$ledgerCount)throw new RuntimeException('重复结算产生了额外流水');
    $detailAfterSecond=Db::name('bet_details')->where('id',$detailId)->find();
    if($detailAfterSecond['status']!==$detail['status']||(float)$detailAfterSecond['win_amount']!==(float)$detail['win_amount'])throw new RuntimeException('重复结算改写了已结算明细');

    $legacyIssue='LEGACY-ODDS-'.bin2hex(random_bytes(4));
    [$legacyRecordId,$legacyDetailId]=createSettlementFixture($user,$lottery,$legacyIssue,null);
    try{$service->settleForHistory(['code'=>$legacyIssue,'numbers'=>'123'],$lottery);throw new RuntimeException('缺少锁定赔率的旧单被静默结算');}
    catch(RuntimeException $error){if(!str_contains($error->getMessage(),'缺少锁定赔率'))throw $error;}
    $legacyRecord=Db::name('bet_records')->where('id',$legacyRecordId)->find();
    $legacyDetail=Db::name('bet_details')->where('id',$legacyDetailId)->find();
    if($legacyRecord['status']!=='pending'||$legacyDetail['status']!=='pending'||$legacyDetail['odds']!==null)throw new RuntimeException('旧单赔率回退失败后没有完整回滚');
    if(Db::name('organization_credit_ledger')->where('related_bet_record_id',$legacyRecordId)->count()>0)throw new RuntimeException('旧单赔率回退失败后遗留账本流水');

    $failureUser=Db::name('site_users')->where('id',(int)$unassigned['id'])->find();
    Db::name('site_users')->where('id',(int)$failureUser['id'])->update(['site_id'=>(int)$siteWithoutRoot['id'],'organization_id'=>null]);
    $failureUser['site_id']=(int)$siteWithoutRoot['id'];$failureUser['organization_id']=null;
    $failureIssue='ROLLBACK-'.bin2hex(random_bytes(4));
    [$failureRecordId,$failureDetailId]=createSettlementFixture($failureUser,$lottery,$failureIssue,3.0);
    $failureState=Db::name('site_users')->where('id',(int)$failureUser['id'])->find();
    $failureLedgerBefore=(int)Db::name('organization_credit_ledger')->where('related_bet_record_id',$failureRecordId)->count();
    try{$service->settleForHistory(['code'=>$failureIssue,'numbers'=>'999'],$lottery);throw new RuntimeException('缺少根股东时结算没有失败');}
    catch(RuntimeException $error){if(!str_contains($error->getMessage(),'未配置根股东'))throw $error;}
    $failedRecord=Db::name('bet_records')->where('id',$failureRecordId)->find();
    $failedDetail=Db::name('bet_details')->where('id',$failureDetailId)->find();
    $failedUser=Db::name('site_users')->where('id',(int)$failureUser['id'])->find();
    if($failedRecord['status']!=='pending'||$failedDetail['status']!=='pending')throw new RuntimeException('结算异常后主单或明细未回滚');
    assertNear((float)$failedUser['balance'],(float)$failureState['balance'],'结算异常后用户余额未回滚');
    assertNear((float)$failedUser['used_balance'],(float)$failureState['used_balance'],'结算异常后用户占用额度未回滚');
    if((int)Db::name('organization_credit_ledger')->where('related_bet_record_id',$failureRecordId)->count()!==$failureLedgerBefore)throw new RuntimeException('结算异常后遗留账本流水');

    Db::rollback();
    echo "BetSettlement locked-odds, idempotency and rollback tests passed\n";
}catch(Throwable $error){Db::rollback();throw $error;}
