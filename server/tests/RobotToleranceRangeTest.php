<?php
declare(strict_types=1);

$serverRoot=getenv('KPS_SERVER_ROOT') ?: dirname(__DIR__);
require $serverRoot.'/vendor/autoload.php';
$controllerFile=getenv('KPS_TEST_CONTROLLER');
if(is_string($controllerFile)&&$controllerFile!=='') require $controllerFile;
$app=new think\App($serverRoot);
$app->initialize();

use app\controller\AdminBetBatch;
use think\facade\Db;

function toleranceCheck(bool $condition,string $message): void
{
    if(!$condition) throw new RuntimeException($message);
}

$lottery=Db::name('lotteries')->where('status',1)->whereNull('deleted_at')->order('id')->find();
$site=Db::name('sites')->whereNull('deleted_at')->field('id,tenant_id')->order('id')->find();
if(!$lottery||!$site) throw new RuntimeException('缺少彩种或站点基础数据');
$lotteryId=(int)$lottery['id'];$lotteryName=(string)$lottery['name'];$siteId=(int)$site['id'];$tenantId=(int)$site['tenant_id'];
$prefix='rbtol_'.bin2hex(random_bytes(4));$issue='RBTOL-'.bin2hex(random_bytes(4));$draw='123';$now=date('Y-m-d H:i:s');
$makeNode=static function(string $name,string $level,int $parentId)use($tenantId,$siteId,$prefix,$now):int{
    $id=(int)Db::name('organization_nodes')->insertGetId(['tenant_id'=>$tenantId,'site_id'=>$siteId,'parent_id'=>$parentId,'level'=>$level,'name'=>$name,
        'code'=>$prefix.'_'.$name,'path'=>'/','depth'=>0,'credit_limit'=>0,'balance'=>0,'permissions'=>'["*"]','settings'=>'{}','status'=>1,
        'created_at'=>$now,'updated_at'=>$now]);
    \app\service\OrganizationHierarchy::rebuildPath($id);
    return $id;
};
$makeRobot=static function(int $nodeId)use($tenantId,$siteId,$prefix,$now):int{
    $userId=(int)Db::name('site_users')->insertGetId(['tenant_id'=>$tenantId,'site_id'=>$siteId,'organization_id'=>$nodeId,'username'=>$prefix,
        'display_name'=>$prefix,'password'=>password_hash($prefix,PASSWORD_DEFAULT),'balance'=>0,'credit_balance'=>0,'used_balance'=>0,
        'used_balance_date'=>date('Y-m-d'),'status'=>1,'created_at'=>$now,'updated_at'=>$now]);
    Db::name('robot_accounts')->insert(['tenant_id'=>$tenantId,'site_id'=>$siteId,'organization_id'=>$nodeId,'user_id'=>$userId,
        'name'=>$prefix,'username'=>$prefix,'plain_password'=>'fixture','min_amount'=>'1.00','max_amount'=>'100.00','amount_precision'=>0,
        'start_at'=>$now,'next_run_at'=>$now,'interval_min'=>0,'interval_max'=>0,'weight_fu'=>'1.00','weight_ti'=>'1.00','weight_futi'=>'1.00',
        'lottery_configs'=>'[]','status'=>'stopped','created_at'=>$now,'updated_at'=>$now]);
    return $userId;
};
$makeRecord=static function(int $userId)use($tenantId,$siteId,$lotteryName,$issue,$now):void{
    $source='福456直10元';
    $recordId=(int)Db::name('bet_records')->insertGetId(['tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$userId,'lottery_name'=>$lotteryName,
        'issue_no'=>$issue,'source_text'=>$source,'formatted_text'=>$source,'bet_count'=>1,'amount'=>'10.00','win_amount'=>'0.00','status'=>'pending','sealed'=>0,
        'placed_at'=>$now,'created_at'=>$now]);
    $detailId=(int)Db::name('bet_details')->insertGetId(['tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$userId,'bet_record_id'=>$recordId,
        'lottery_name'=>$lotteryName,'issue_no'=>$issue,'number_text'=>'456','category'=>'直选','amount'=>'10.00','odds'=>'900.0000',
        'win_amount'=>'0.00','rebate'=>'0.00','status'=>'pending','placed_at'=>$now,'source_text'=>$source]);
    Db::name('user_stop_drops')->insert(['tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$userId,'bet_detail_id'=>$detailId,'lottery'=>$lotteryName,
        'issue_no'=>$issue,'number_text'=>'456','play_type'=>'直选','stop_type'=>'none','original_amount'=>'10.00','actual_amount'=>'10.00',
        'stop_amount'=>'0.00','original_odds'=>'900.0000','actual_odds'=>'900.0000','drop_odds'=>'0.0000','source_text'=>$source,
        'placed_at'=>$now,'created_at'=>$now]);
};

Db::startTrans();
try{
    $director=$makeNode('director','director',0);
    $shareholder=$makeNode('shareholder','shareholder',$director);
    $robot=$makeRobot($shareholder);$makeRecord($robot);
    $controller=new AdminBetBatch();
    $build=new ReflectionMethod($controller,'buildNumberOnlyRobotPlan');$build->setAccessible(true);
    $lotteryRow=['id'=>$lotteryId,'name'=>$lotteryName];
    $positive=$build->invoke($controller,$lotteryRow,$issue,$draw,[$robot],8000.0,$shareholder,null);
    $negative=$build->invoke($controller,$lotteryRow,$issue,$draw,[$robot],-1000.0,$shareholder,null);
    toleranceCheck((float)$positive['target_min']===7600.0&&(float)$positive['target_max']===8400.0,'正目标8000的5%区间必须是7600到8400');
    toleranceCheck((float)$negative['target_min']===-1050.0&&(float)$negative['target_max']===-950.0,'负目标-1000的5%区间必须是-1050到-950');
    foreach([$positive,$negative] as $plan){
        $after=(float)$plan['daily_profit_after'];$min=(float)$plan['target_min'];$max=(float)$plan['target_max'];
        $expected=$after>=$min-0.005&&$after<=$max+0.005;
        toleranceCheck((bool)$plan['within_tolerance']===$expected,'容差标志必须严格按返回的5%区间计算');
        toleranceCheck(!str_contains(implode(' ',(array)$plan['warnings']),'30%'),'警告文案不得残留30%');
    }
    Db::rollback();
    echo "Robot tolerance range test passed: positive 7600-8400, negative -1050--950; fixtures rolled back\n";
}catch(Throwable $error){Db::rollback();throw $error;}
