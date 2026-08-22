<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));
$app->initialize();

use think\facade\Db;

$controller=new app\controller\UserBusiness();
$quickLines=new ReflectionMethod($controller,'quickLines');$quickLines->setAccessible(true);
$lineForLottery=new ReflectionMethod($controller,'lineForLottery');$lineForLottery->setAccessible(true);
$fingerprintMethod=new ReflectionMethod($controller,'submissionFingerprint');$fingerprintMethod->setAccessible(true);
$duplicatesMethod=new ReflectionMethod($controller,'recentDuplicateRecords');$duplicatesMethod->setAccessible(true);

$fc=Db::name('lotteries')->where('name','福彩3D')->whereNull('deleted_at')->find();
$pl=Db::name('lotteries')->where('name','排列三')->whereNull('deleted_at')->find();
if(!$fc||!$pl||((int)$fc['tenant_id']!==(int)$pl['tenant_id']))throw new RuntimeException('测试彩种不完整');
$tenantId=(int)$fc['tenant_id'];

Db::startTrans();
try{
    Db::name('lotteries')->where('id',(int)$fc['id'])->update(['unit_stake'=>'2.00']);
    Db::name('lotteries')->where('id',(int)$pl['id'])->update(['unit_stake'=>'5.00']);

    $explicit=$quickLines->invoke($controller,'体123直10倍','福彩3D',$tenantId)[0]??null;
    $explicitPl=$lineForLottery->invoke($controller,$explicit,'排列三',$tenantId);
    if(($explicitPl['amount']??'')!=='50.00')throw new RuntimeException('显式体彩未按体彩 unit_stake 计算: '.json_encode($explicitPl,JSON_UNESCAPED_UNICODE));

    $both=$quickLines->invoke($controller,'福体123直10倍','福彩3D',$tenantId)[0]??null;
    $bothFc=$lineForLottery->invoke($controller,$both,'福彩3D',$tenantId);
    $bothPl=$lineForLottery->invoke($controller,$both,'排列三',$tenantId);
    if(($bothFc['amount']??'')!=='20.00'||($bothPl['amount']??'')!=='50.00'||abs((float)$bothFc['amount']+(float)$bothPl['amount']-70.0)>0.001)throw new RuntimeException('福体未按两彩种 unit_stake 分别计算');

    $user=Db::name('site_users')->whereNull('deleted_at')->field('id,site_id,tenant_id')->find();
    if(!$user)throw new RuntimeException('缺少防重测试用户');
    $session=['tenant_id'=>(int)$user['tenant_id'],'site_id'=>(int)$user['site_id'],'user_id'=>(int)$user['id']];
    $entry=['line'=>['play_type'=>'组六','number_text'=>'123'],'rule'=>['requested'=>20,'actual'=>20]];
    $fcGroups=['福彩3D'=>['issue_no'=>'TEST-ISSUE','lines'=>[$entry]]];
    $plGroups=['排列三'=>['issue_no'=>'TEST-ISSUE','lines'=>[$entry]]];
    $fcFingerprint=$fingerprintMethod->invoke($controller,$session,$fcGroups);
    $plFingerprint=$fingerprintMethod->invoke($controller,$session,$plGroups);
    if($fcFingerprint===$plFingerprint)throw new RuntimeException('默认福彩与默认体彩指纹不应相同');
    $now=date('Y-m-d H:i:s');
    Db::name('bet_records')->insert(['tenant_id'=>$session['tenant_id'],'site_id'=>$session['site_id'],'user_id'=>$session['user_id'],'issue_no'=>'TEST-ISSUE','source_text'=>'123组六20','formatted_text'=>'123组6 20','submission_fingerprint'=>$fcFingerprint,'bet_count'=>1,'amount'=>'20.00','win_amount'=>'0.00','status'=>'pending','sealed'=>0,'placed_at'=>$now,'created_at'=>$now]);
    if(count($duplicatesMethod->invoke($controller,$session,$fcFingerprint))!==1)throw new RuntimeException('同彩种相同提交未命中防重');
    if(count($duplicatesMethod->invoke($controller,$session,$plFingerprint))!==0)throw new RuntimeException('福彩注单错误拦截了体彩同文本注单');
    Db::name('bet_records')->insert(['tenant_id'=>$session['tenant_id'],'site_id'=>$session['site_id'],'user_id'=>$session['user_id'],'issue_no'=>'TEST-ISSUE','source_text'=>'123组六20','formatted_text'=>'123组6 20','submission_fingerprint'=>$plFingerprint,'bet_count'=>1,'amount'=>'20.00','win_amount'=>'0.00','status'=>'pending','sealed'=>0,'placed_at'=>$now,'created_at'=>$now]);
    if(count($duplicatesMethod->invoke($controller,$session,$plFingerprint))!==1)throw new RuntimeException('体彩同文本合法订单未能独立记录');
    Db::rollback();
}catch(Throwable $e){Db::rollback();throw $e;}

echo "QuickEntryController tests passed\n";
