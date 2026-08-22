<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));
$app->initialize();

use app\service\BetSettlement;
use think\facade\Db;

function pairedFixture(array $user,array $lottery,string $issue): array
{
    $now=date('Y-m-d H:i:s');
    $recordId=(int)Db::name('bet_records')->insertGetId([
        'tenant_id'=>(int)$user['tenant_id'],'site_id'=>(int)$user['site_id'],'user_id'=>(int)$user['id'],
        'issue_no'=>$issue,'source_text'=>'和值13-14 100元','formatted_text'=>'和值13-14 100元','bet_count'=>2,
        'amount'=>'100.00','win_amount'=>'0.00','status'=>'pending','sealed'=>1,'placed_at'=>$now,'created_at'=>$now,
    ]);
    $detailId=(int)Db::name('bet_details')->insertGetId([
        'tenant_id'=>(int)$user['tenant_id'],'site_id'=>(int)$user['site_id'],'user_id'=>(int)$user['id'],
        'bet_record_id'=>$recordId,'issue_no'=>$issue,'number_text'=>'013 014','category'=>'福','amount'=>'100.00',
        'odds'=>'12.000','win_amount'=>'0.00','rebate'=>'0.00','status'=>'pending','placed_at'=>$now,'source_text'=>'和值13-14 福',
    ]);
    Db::name('user_stop_drops')->insert([
        'tenant_id'=>(int)$user['tenant_id'],'site_id'=>(int)$user['site_id'],'user_id'=>(int)$user['id'],
        'bet_detail_id'=>$detailId,'lottery'=>(string)$lottery['name'],'issue_no'=>$issue,'number_text'=>'013 014','play_type'=>'和值13-14',
        'stop_type'=>'none','original_amount'=>'100.00','actual_amount'=>'100.00','stop_amount'=>'0.00',
        'original_odds'=>'12.000','actual_odds'=>'12.000','drop_odds'=>'0.000','source_text'=>'和值13-14 福','placed_at'=>$now,'created_at'=>$now,
    ]);
    Db::name('site_users')->where('id',(int)$user['id'])->update(['used_balance'=>Db::raw('used_balance + 100.00')]);
    return [$recordId,$detailId];
}

$lottery=Db::name('lotteries')->where('name','福彩3D')->where('status',1)->whereNull('deleted_at')->find();
$user=Db::name('site_users')->whereNotNull('organization_id')->where('status',1)->whereNull('deleted_at')->order('id')->find();
if(!$lottery||!$user)throw new RuntimeException('缺少成对和值结算测试基础数据');
$service=new BetSettlement();

Db::startTrans();
try{
    $before=Db::name('site_users')->where('id',(int)$user['id'])->find();
    $winIssue='PAIR-SUM-WIN-'.bin2hex(random_bytes(4));
    [$winRecordId,$winDetailId]=pairedFixture($user,$lottery,$winIssue);
    $result=$service->settleForHistory(['code'=>$winIssue,'numbers'=>'049'],$lottery);
    if($result!==['records'=>1,'won'=>1])throw new RuntimeException('成对和值端点中奖未成功结算');
    $winRecord=Db::name('bet_records')->where('id',$winRecordId)->find();
    $winDetail=Db::name('bet_details')->where('id',$winDetailId)->find();
    $afterWin=Db::name('site_users')->where('id',(int)$user['id'])->find();
    if(abs((float)$winRecord['win_amount']-600.0)>0.001||abs((float)$winDetail['win_amount']-600.0)>0.001)throw new RuntimeException('100元和值13-14、赔率12、和值13时应赔 50×12=600');
    if(abs((float)$afterWin['balance']-((float)$before['balance']+500.0))>0.001)throw new RuntimeException('成对和值中奖后的用户余额错误');

    $lossIssue='PAIR-SUM-LOSS-'.bin2hex(random_bytes(4));
    [$lossRecordId,$lossDetailId]=pairedFixture($user,$lottery,$lossIssue);
    $beforeLoss=Db::name('site_users')->where('id',(int)$user['id'])->find();
    $lossResult=$service->settleForHistory(['code'=>$lossIssue,'numbers'=>'048'],$lottery);
    if($lossResult!==['records'=>1,'won'=>0])throw new RuntimeException('非端点和值注单结算状态错误');
    $lossRecord=Db::name('bet_records')->where('id',$lossRecordId)->find();
    $lossDetail=Db::name('bet_details')->where('id',$lossDetailId)->find();
    $afterLoss=Db::name('site_users')->where('id',(int)$user['id'])->find();
    if((float)$lossRecord['win_amount']!==0.0||(float)$lossDetail['win_amount']!==0.0)throw new RuntimeException('非端点和值错误赔付');
    if(abs((float)$afterLoss['balance']-((float)$beforeLoss['balance']-100.0))>0.001)throw new RuntimeException('成对和值未中奖后的用户余额错误');

    Db::rollback();
    echo "Paired sum settlement tests passed: 100 at odds 12 pays exactly 600 for one endpoint\n";
}catch(Throwable $error){Db::rollback();throw $error;}
