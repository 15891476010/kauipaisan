<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));
$app->initialize();

use app\controller\AdminBetBatch;
use think\facade\Db;

function numberOnlyCheck(bool $condition,string $message): void
{
    if(!$condition) throw new RuntimeException($message);
}

$lottery=Db::name('lotteries')->where('status',1)->whereNull('deleted_at')->order('id')->find();
$site=Db::name('sites')->whereNull('deleted_at')->field('id,tenant_id')->order('id')->find();
if(!$lottery||!$site) throw new RuntimeException('缺少彩种或站点基础数据');
$lotteryId=(int)$lottery['id'];$lotteryName=(string)$lottery['name'];$siteId=(int)$site['id'];$tenantId=(int)$site['tenant_id'];
$prefix='rbnum_'.bin2hex(random_bytes(4));$issue='RBNUM-'.bin2hex(random_bytes(4));$draw='123';$now=date('Y-m-d H:i:s');
$nodeId=0;
$makeUser=static function(string $name,bool $robot)use($tenantId,$siteId,&$nodeId,$now):int{
    $userId=(int)Db::name('site_users')->insertGetId(['tenant_id'=>$tenantId,'site_id'=>$siteId,'organization_id'=>$nodeId,'username'=>$name,
        'display_name'=>$name,'password'=>password_hash($name,PASSWORD_DEFAULT),'balance'=>0,'credit_balance'=>0,'used_balance'=>0,
        'used_balance_date'=>date('Y-m-d'),'status'=>1,'created_at'=>$now,'updated_at'=>$now]);
    if($robot)Db::name('robot_accounts')->insert(['tenant_id'=>$tenantId,'site_id'=>$siteId,'organization_id'=>$nodeId,'user_id'=>$userId,
        'name'=>$name,'username'=>$name,'plain_password'=>'fixture','min_amount'=>'1.00','max_amount'=>'100.00','amount_precision'=>0,
        'start_at'=>$now,'next_run_at'=>$now,'interval_min'=>3,'interval_max'=>5,'weight_fu'=>'1.00','weight_ti'=>'1.00','weight_futi'=>'1.00',
        'lottery_configs'=>'[]','status'=>'stopped','created_at'=>$now,'updated_at'=>$now]);
    return $userId;
};
$makeRecord=static function(int $userId,string $number,string $amount)use($tenantId,$siteId,$lotteryName,$issue,$now):array{
    $source='福'.$number.'直'.$amount.'元';
    $recordId=(int)Db::name('bet_records')->insertGetId(['tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$userId,'lottery_name'=>$lotteryName,
        'issue_no'=>$issue,'source_text'=>$source,'formatted_text'=>$source,'bet_count'=>1,'amount'=>$amount,'win_amount'=>'0.00','status'=>'pending','sealed'=>0,
        'placed_at'=>$now,'created_at'=>$now]);
    $detailId=(int)Db::name('bet_details')->insertGetId(['tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$userId,'bet_record_id'=>$recordId,
        'lottery_name'=>$lotteryName,'issue_no'=>$issue,'number_text'=>$number,'category'=>'直选','amount'=>$amount,'odds'=>'900.0000',
        'win_amount'=>'0.00','rebate'=>'0.00','status'=>'pending','placed_at'=>$now,'source_text'=>$source]);
    Db::name('user_stop_drops')->insert(['tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$userId,'bet_detail_id'=>$detailId,'lottery'=>$lotteryName,
        'issue_no'=>$issue,'number_text'=>$number,'play_type'=>'直选','stop_type'=>'none','original_amount'=>$amount,'actual_amount'=>$amount,
        'stop_amount'=>'0.00','original_odds'=>'900.0000','actual_odds'=>'900.0000','drop_odds'=>'0.0000','source_text'=>$source,
        'placed_at'=>$now,'created_at'=>$now]);
    return [$recordId,$detailId];
};

Db::startTrans();
try{
    $nodeId=(int)Db::name('organization_nodes')->insertGetId(['tenant_id'=>$tenantId,'site_id'=>$siteId,'parent_id'=>0,'level'=>'agent','name'=>$prefix,
        'code'=>$prefix,'path'=>'/','depth'=>0,'credit_limit'=>0,'balance'=>0,'permissions'=>'["*"]','settings'=>'{}','status'=>1,
        'created_at'=>$now,'updated_at'=>$now]);
    \app\service\OrganizationHierarchy::rebuildPath($nodeId);
    $member=$makeUser($prefix.'_member',false);$robot=$makeUser($prefix.'_robot',true);
    $makeRecord($member,'789','1000.00');
    [$loseRecord,$loseDetail]=$makeRecord($robot,'456','10.00');
    [$winRecord]=$makeRecord($robot,'123','1.00');

    $controller=new AdminBetBatch();
    $build=new ReflectionMethod($controller,'buildNumberOnlyRobotPlan');$build->setAccessible(true);
    $apply=new ReflectionMethod($controller,'applyRobotNumberOnlyItem');$apply->setAccessible(true);
    $snapshot=new ReflectionMethod($controller,'robotAmountSnapshot');$snapshot->setAccessible(true);
    $lotteryRow=['id'=>$lotteryId,'name'=>$lotteryName];

    $positive=$build->invoke($controller,$lotteryRow,$issue,$draw,[$robot],8000.0,$nodeId,null);
    numberOnlyCheck($positive['within_tolerance']===true,'正目标应落在上下30%区间');
    numberOnlyCheck((float)$positive['daily_profit_before']===-111.0,'基线应包含普通会员和机器人');
    numberOnlyCheck(count($positive['items'])===1&&$positive['items'][0]['action']==='win','正目标应只翻一张机器人输单');
    numberOnlyCheck((int)$positive['items'][0]['record_id']===$loseRecord,'普通会员注单不得进入改码方案');
    numberOnlyCheck((float)$positive['items'][0]['old_amount']===(float)$positive['items'][0]['new_amount'],'方案金额必须不变');

    $negative=$build->invoke($controller,$lotteryRow,$issue,$draw,[$robot],-1000.0,$nodeId,null);
    numberOnlyCheck($negative['within_tolerance']===true,'负目标应落在上下30%区间');
    numberOnlyCheck(count($negative['items'])===1&&$negative['items'][0]['action']==='lose','负目标应把机器人赢单改为不中奖');
    numberOnlyCheck((int)$negative['items'][0]['record_id']===$winRecord,'负目标应选择当前赢单');

    $before=$snapshot->invoke($controller,$positive['items']);$settled=[];
    $apply->invokeArgs($controller,[$positive['items'][0],$issue,null,&$settled]);
    $after=$snapshot->invoke($controller,$positive['items']);
    numberOnlyCheck(hash_equals($before,$after),'bet_records/bet_details/user_stop_drops/bet_submissions 金额哈希必须完全一致');
    numberOnlyCheck((string)Db::name('bet_details')->where('id',$loseDetail)->value('number_text')===$draw,'只应把机器人号码改为预开奖号');

    $fresh=$build->invoke($controller,$lotteryRow,$issue,$draw,[$robot],8000.0,$nodeId,null);
    numberOnlyCheck(!hash_equals((string)$positive['plan_token'],(string)$fresh['plan_token']),'号码变化后旧方案令牌必须失效');
    Db::rollback();
    echo "RobotNumberOnlyPlanTest passed; fixtures rolled back\n";
}catch(Throwable $error){Db::rollback();throw $error;}
