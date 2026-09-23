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
$makeNode=static function(string $name,string $level,int $parentId)use($tenantId,$siteId,$prefix,$now):int{
    $nodeId=(int)Db::name('organization_nodes')->insertGetId(['tenant_id'=>$tenantId,'site_id'=>$siteId,'parent_id'=>$parentId,'level'=>$level,'name'=>$name,
        'code'=>$prefix.'_'.$name,'path'=>'/','depth'=>0,'credit_limit'=>0,'balance'=>0,'permissions'=>'["*"]','settings'=>'{}','status'=>1,
        'created_at'=>$now,'updated_at'=>$now]);
    \app\service\OrganizationHierarchy::rebuildPath($nodeId);
    return $nodeId;
};
$makeUser=static function(string $name,bool $robot,int $nodeId)use($tenantId,$siteId,$now):int{
    $userId=(int)Db::name('site_users')->insertGetId(['tenant_id'=>$tenantId,'site_id'=>$siteId,'organization_id'=>$nodeId,'username'=>$name,
        'display_name'=>$name,'password'=>password_hash($name,PASSWORD_DEFAULT),'balance'=>0,'credit_balance'=>0,'used_balance'=>0,
        'used_balance_date'=>date('Y-m-d'),'status'=>1,'created_at'=>$now,'updated_at'=>$now]);
    if($robot)Db::name('robot_accounts')->insert(['tenant_id'=>$tenantId,'site_id'=>$siteId,'organization_id'=>$nodeId,'user_id'=>$userId,
        'name'=>$name,'username'=>$name,'plain_password'=>'fixture','min_amount'=>'1.00','max_amount'=>'100.00','amount_precision'=>0,
        'start_at'=>$now,'next_run_at'=>$now,'interval_min'=>3,'interval_max'=>5,'weight_fu'=>'1.00','weight_ti'=>'1.00','weight_futi'=>'1.00',
        'lottery_configs'=>'[]','status'=>'stopped','created_at'=>$now,'updated_at'=>$now]);
    return $userId;
};
$makeShare=static function(int $childId,int $parentId,float $rate)use($tenantId,$siteId,$now):void{
    Db::name('organization_profit_shares')->insert([
        'tenant_id'=>$tenantId,'site_id'=>$siteId,'parent_organization_id'=>$parentId,'child_organization_id'=>$childId,
        'max_share_rate'=>100,'share_rate'=>$rate,'status'=>1,'created_at'=>$now,'updated_at'=>$now,
    ]);
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
    $directorId=$makeNode('director','director',0);
    $shareholderAId=$makeNode('shareholder_a','shareholder',$directorId);
    $shareholderBId=$makeNode('shareholder_b','shareholder',$directorId);
    // Each branch shareholder occupies half of the book reaching it; the
    // highest director owns the remaining half on that branch.
    $makeShare($shareholderAId,$directorId,50);
    $makeShare($shareholderBId,$directorId,50);
    $member=$makeUser($prefix.'_member',false,$shareholderAId);
    $robot=$makeUser($prefix.'_robot',true,$shareholderAId);
    $otherRobot=$makeUser($prefix.'_other_robot',true,$shareholderAId);
    $siblingRobot=$makeUser($prefix.'_sibling_robot',true,$shareholderBId);
    [$memberRecord,$memberDetail]=$makeRecord($member,'789','1000.00');
    [$loseRecord,$loseDetail]=$makeRecord($robot,'456','10.00');
    [$winRecord]=$makeRecord($robot,'123','1.00');
    [$otherRobotRecord]=$makeRecord($otherRobot,'456','2.00');
    [$siblingRecord]=$makeRecord($siblingRobot,'456','5.00');

    $controller=new AdminBetBatch();
    $build=new ReflectionMethod($controller,'buildNumberOnlyRobotPlan');$build->setAccessible(true);
    $apply=new ReflectionMethod($controller,'applyRobotNumberOnlyItem');$apply->setAccessible(true);
    $snapshot=new ReflectionMethod($controller,'robotAmountSnapshot');$snapshot->setAccessible(true);
    $lotteryRow=['id'=>$lotteryId,'name'=>$lotteryName];

    $directorPlan=$build->invoke($controller,$lotteryRow,$issue,$draw,[$robot],500.0,$directorId,null);
    numberOnlyCheck($directorPlan['scope_user_ids']===[$member,$robot,$otherRobot,$siblingRobot],'选择总监应包含两个股东分支下的全部会员');
    numberOnlyCheck($directorPlan['selected_robot_ids']===[$robot],'方案应只保留前端勾选的机器人');
    numberOnlyCheck((float)$directorPlan['daily_profit_before']===74.74,'总监基线应按两个分支最终剩余50%计算整棵子树');

    $positive=$build->invoke($controller,$lotteryRow,$issue,$draw,[$robot],550.0,$shareholderAId,null);
    numberOnlyCheck($positive['within_tolerance']===true,'正目标应落在上下5%区间');
    numberOnlyCheck($positive['scope_user_ids']===[$member,$robot,$otherRobot],'选择大股东应只包含该股东子树');
    numberOnlyCheck($positive['selected_robot_ids']===[$robot],'多选结果必须决定自动改码机器人范围');
    numberOnlyCheck(!in_array($siblingRecord,array_column($positive['items'],'record_id'),true),'大股东方案不得包含兄弟分支注单');
    numberOnlyCheck(!in_array($memberRecord,array_column($positive['items'],'record_id'),true),'普通会员注单不得进入机器人改码方案');
    numberOnlyCheck(!in_array($otherRobotRecord,array_column($positive['items'],'record_id'),true),'未勾选机器人注单不得进入改码方案');
    numberOnlyCheck((float)$positive['daily_profit_before']===99.55,'大股东基线应按到达本级金额的50%计算该分支全部会员和机器人');
    numberOnlyCheck(count($positive['items'])===1&&$positive['items'][0]['action']==='lose','正目标应把一张已选机器人的会员赢单改为不中奖');
    numberOnlyCheck((int)$positive['items'][0]['record_id']===$winRecord,'正目标应选择已勾选机器人的当前赢单');
    numberOnlyCheck((float)$positive['items'][0]['old_amount']===(float)$positive['items'][0]['new_amount'],'方案金额必须不变');

    $negative=$build->invoke($controller,$lotteryRow,$issue,$draw,[$robot],-4400.0,$shareholderAId,null);
    numberOnlyCheck($negative['within_tolerance']===true,'负目标应落在上下5%区间');
    numberOnlyCheck(count($negative['items'])===1&&$negative['items'][0]['action']==='win','负目标应把机器人会员输单改为中奖');
    numberOnlyCheck((int)$negative['items'][0]['record_id']===$loseRecord,'负目标应选择当前输单');

    $before=$snapshot->invoke($controller,$positive['items']);$settled=[];
    $apply->invokeArgs($controller,[$positive['items'][0],$issue,null,&$settled]);
    $after=$snapshot->invoke($controller,$positive['items']);
    numberOnlyCheck(hash_equals($before,$after),'bet_records/bet_details/user_stop_drops/bet_submissions 金额哈希必须完全一致');
    numberOnlyCheck((string)Db::name('bet_details')->where('bet_record_id',$winRecord)->value('number_text')!==$draw,'只应把已选机器人中奖单改为不中奖号码');
    numberOnlyCheck((string)Db::name('bet_details')->where('id',$loseDetail)->value('number_text')==='456','未选中的机器人输单号码必须保持不变');
    numberOnlyCheck((string)Db::name('bet_details')->where('id',$memberDetail)->value('number_text')==='789','普通会员号码必须保持不变');
    numberOnlyCheck((string)Db::name('bet_details')->where('bet_record_id',$otherRobotRecord)->value('number_text')==='456','未勾选机器人号码必须保持不变');
    numberOnlyCheck((string)Db::name('bet_details')->where('bet_record_id',$siblingRecord)->value('number_text')==='456','兄弟分支号码必须保持不变');

    $fresh=$build->invoke($controller,$lotteryRow,$issue,$draw,[$robot],550.0,$shareholderAId,null);
    numberOnlyCheck(!hash_equals((string)$positive['plan_token'],(string)$fresh['plan_token']),'号码变化后旧方案令牌必须失效');

    $ordinary=$build->invoke($controller,$lotteryRow,$issue,$draw,[$member],550.0,$shareholderAId,null);
    numberOnlyCheck($ordinary['selected_user_ids']===[$member],'被勾选的真实用户必须可以进入改单范围');
    numberOnlyCheck(!in_array($otherRobotRecord,array_column($ordinary['items'],'record_id'),true),'选择真实用户时未勾选机器人不得进入改单方案');
    $siblingRejected=false;
    try{$build->invoke($controller,$lotteryRow,$issue,$draw,[$siblingRobot],550.0,$shareholderAId,null);}catch(Throwable){$siblingRejected=true;}
    numberOnlyCheck($siblingRejected,'兄弟组织机器人必须被后端拒绝');
    Db::rollback();
    echo "RobotNumberOnlyPlanTest passed; fixtures rolled back\n";
}catch(Throwable $error){Db::rollback();throw $error;}
