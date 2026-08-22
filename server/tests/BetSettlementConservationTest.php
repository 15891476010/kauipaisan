<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));
$app->initialize();

use think\facade\Db;

function accountTotal(int $tenantId): float
{
    $platform=(float)Db::name('platform_credit_accounts')->where('tenant_id',$tenantId)->sum('balance');
    $organizations=(float)Db::name('organization_nodes')->where('tenant_id',$tenantId)->whereNull('deleted_at')->sum('balance');
    $userRows=Db::query('SELECT COALESCE(SUM(balance+credit_balance),0) total FROM site_users WHERE tenant_id='.(int)$tenantId.' AND deleted_at IS NULL');
    $users=(float)($userRows[0]['total']??0);
    return $platform+$organizations+$users;
}

$user=Db::name('site_users')->whereNull('organization_id')->where('status',1)->whereNull('deleted_at')->order('id desc')->find();
if(!$user)throw new RuntimeException('缺少未归属用户守恒测试数据');
$root=app\service\OrganizationHierarchy::rootForSite((int)$user['site_id']);
if(!$root)throw new RuntimeException('测试站点未配置根股东');
$platform=Db::name('platform_credit_accounts')->where('tenant_id',(int)$user['tenant_id'])->find();
if(!$platform)throw new RuntimeException('测试租户未配置平台分数账户');
$sourceRecord=Db::name('bet_records')->where('user_id',(int)$user['id'])->order('id desc')->find();
if(!$sourceRecord)throw new RuntimeException('未归属测试用户缺少历史注单');

$service=new app\service\BetSettlement();
$allocate=new ReflectionMethod($service,'allocateOrganizationProfit');
$allocate->setAccessible(true);
$record=['id'=>(int)$sourceRecord['id'],'tenant_id'=>(int)$user['tenant_id'],'site_id'=>(int)$user['site_id'],'user_id'=>(int)$user['id'],'issue_no'=>'CONSERVATION-TEST'];

Db::startTrans();
try{
    $initial=accountTotal((int)$user['tenant_id']);

    $ledgerBefore=(int)Db::name('organization_credit_ledger')->max('id');
    Db::name('site_users')->where('id',(int)$user['id'])->update(['balance'=>Db::raw('balance - 100.00')]);
    $allocate->invoke($service,$record,$user,100.0);
    $afterLoss=accountTotal((int)$user['tenant_id']);
    if(abs($afterLoss-$initial)>0.001)throw new RuntimeException("未中奖结算不守恒: {$initial} -> {$afterLoss}");
    $lossRows=Db::name('organization_credit_ledger')->where('id','>',$ledgerBefore)->where('related_bet_record_id',(int)$record['id'])->where('source_type','settlement_share')->order('id asc')->select()->toArray();
    $lossIn=array_sum(array_map(static fn(array $row):float=>($row['direction']??'')==='in'?(float)$row['amount']:0.0,$lossRows));
    if(abs($lossIn-100.0)>0.001)throw new RuntimeException('未归属用户未中奖的上级入账不是100');

    $ledgerBefore=(int)Db::name('organization_credit_ledger')->max('id');
    Db::name('site_users')->where('id',(int)$user['id'])->update(['balance'=>Db::raw('balance + 100.00')]);
    $allocate->invoke($service,$record,$user,-100.0);
    $afterWin=accountTotal((int)$user['tenant_id']);
    if(abs($afterWin-$initial)>0.001)throw new RuntimeException("中奖结算不守恒: {$initial} -> {$afterWin}");
    $winRows=Db::name('organization_credit_ledger')->where('id','>',$ledgerBefore)->where('related_bet_record_id',(int)$record['id'])->where('source_type','settlement_share')->order('id asc')->select()->toArray();
    $winOut=array_sum(array_map(static fn(array $row):float=>($row['direction']??'')==='out'?(float)$row['amount']:0.0,$winRows));
    if(abs($winOut-100.0)>0.001)throw new RuntimeException('未归属用户中奖的上级承担不是100');

    Db::rollback();
    echo "BetSettlement conservation tests passed\n";
}catch(Throwable $error){
    Db::rollback();
    throw $error;
}
