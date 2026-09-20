<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));
$app->initialize();

use app\service\BetSettlement;
use think\facade\Db;

$agent=Db::name('organization_nodes')->where('level','agent')->whereNull('deleted_at')->order('id')->find();
if(!$agent)throw new RuntimeException('缺少代理层级测试数据');

$leafToRoot=[];$cursor=$agent;$visited=[];
while($cursor&&!in_array((int)$cursor['id'],$visited,true)){
    $visited[]=(int)$cursor['id'];$leafToRoot[]=$cursor;
    $cursor=(int)$cursor['parent_id']>0?Db::name('organization_nodes')->where('id',(int)$cursor['parent_id'])->whereNull('deleted_at')->find():null;
}
$levels=array_column($leafToRoot,'level');
if($levels!==['agent','general_agent','small_shareholder','shareholder','director'])throw new RuntimeException('测试代理没有完整五级上级链');

$user=Db::name('site_users')->where('organization_id',(int)$agent['id'])->whereNull('deleted_at')->order('id')->find();
if(!$user)throw new RuntimeException('测试代理缺少直属会员');
$sourceRecord=Db::name('bet_records')->where('user_id',(int)$user['id'])->order('id desc')->find();
if(!$sourceRecord)throw new RuntimeException('测试会员缺少历史注单');

$record=['id'=>(int)$sourceRecord['id'],'tenant_id'=>(int)$user['tenant_id'],'site_id'=>(int)$user['site_id'],'user_id'=>(int)$user['id'],'issue_no'=>'SEQUENTIAL-SHARE-DB-TEST','amount'=>'2000.00'];
// 直比占成：每层 share_rate 是对会员总投的直接占比；入账 = 占比×净结果
// − 水钱率×占成金额(占比×总投2000)。
$turnover=2000.0;
$rates=['agent'=>10.0,'general_agent'=>5.0,'small_shareholder'=>2.0,'shareholder'=>10.0,'director'=>55.0];
$siteSettings=Db::name('sites')->where('id',(int)$user['site_id'])->value('settings');
$siteSettings=is_string($siteSettings)?json_decode($siteSettings,true):(is_array($siteSettings)?$siteSettings:[]);
$waterRate=max(0,min(1,(float)($siteSettings['water_rate']??$siteSettings['dark_water_rate']??0.085)));
$expected=[];$expectedSum=0.0;
foreach($rates as $lv=>$r){
    $occupied=$r/100*$turnover;
    $expected[$lv]=round(round(10000.0*$r/100,2)-round($waterRate*$occupied,2),2);
    $expectedSum+=$expected[$lv];
}
$now=date('Y-m-d H:i:s');

Db::startTrans();
try{
    foreach($leafToRoot as $node){
        $data=['tenant_id'=>(int)$node['tenant_id'],'site_id'=>(int)$node['site_id'],'parent_organization_id'=>(int)$node['parent_id'],'child_organization_id'=>(int)$node['id'],'max_share_rate'=>'100.0000','share_rate'=>number_format($rates[(string)$node['level']],4,'.',''),'status'=>1,'updated_at'=>$now];
        $existing=Db::name('organization_profit_shares')->where('child_organization_id',(int)$node['id'])->find();
        if($existing)Db::name('organization_profit_shares')->where('id',(int)$existing['id'])->update($data);
        else{$data['created_at']=$now;Db::name('organization_profit_shares')->insert($data);}
    }
    $settings=Db::name('sites')->where('id',(int)$user['site_id'])->value('settings');
    $settings=is_string($settings)?json_decode($settings,true):(is_array($settings)?$settings:[]);$settings['max_profit_share_rate']=100;
    Db::name('sites')->where('id',(int)$user['site_id'])->update(['settings'=>json_encode($settings,JSON_UNESCAPED_UNICODE)]);

    $beforeLedger=(int)Db::name('organization_credit_ledger')->max('id');
    $method=new ReflectionMethod(new BetSettlement(),'allocateOrganizationProfit');$method->setAccessible(true);
    $method->invoke(new BetSettlement(),$record,$user,10000.0);
    $rows=Db::name('organization_credit_ledger')->where('id','>',$beforeLedger)->where('related_bet_record_id',(int)$record['id'])->where('source_type','settlement_share')->select()->toArray();
    $byOrganization=[];foreach($rows as $row)$byOrganization[(int)$row['organization_id']]=$row;

    $sum=0.0;
    foreach($leafToRoot as $node){
        $row=$byOrganization[(int)$node['id']]??null;if(!$row)throw new RuntimeException((string)$node['level'].' 没有生成占成流水');
        $amount=(float)$row['amount'];$sum+=$amount;
        if(abs($amount-$expected[(string)$node['level']])>0.001)throw new RuntimeException((string)$node['level'].' 占成金额错误：'.$amount);
        $metadata=json_decode((string)($row['metadata']??''),true)?:[];
        if(($metadata['allocation_method']??'')!=='direct_share')throw new RuntimeException('占成流水缺少直比分配标记');
        if(($metadata['rate_mode']??'')!=='direct')throw new RuntimeException('占成流水缺少直比占成标记');
        if((int)($metadata['line_organization_id']??0)!==(int)$agent['id'])throw new RuntimeException('占成流水线路归属错误');
    }
    if(abs($sum-$expectedSum)>0.001)throw new RuntimeException('数据库直比占成分配不守恒：'.$sum.' != '.$expectedSum);

    Db::rollback();
    echo "Sequential profit share database tests passed: 10000 distributed through five levels\n";
}catch(Throwable $error){Db::rollback();throw $error;}
