<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));
$app->initialize();

use app\service\InterceptionAllocator;
use think\facade\Cache;
use think\facade\Db;

function interceptionSettings(int $lotteryId,int $oddsId,float $limit,bool $follow): string
{
    return json_encode(['agent_interception_amounts_'.$lotteryId=>[(string)$oddsId=>(string)$limit],'agent_follow_platform_interception'=>$follow?1:0],JSON_UNESCAPED_UNICODE);
}

function usage(int $tenantId,string $scope,int $scopeId,int $lotteryId,string $issue,int $oddsId,string $number): float
{
    return (float)Db::name('interception_capacity_usage')->where(['tenant_id'=>$tenantId,'scope_type'=>$scope,'scope_id'=>$scopeId,'lottery_id'=>$lotteryId,'lottery_odds_id'=>$oddsId,'issue_no'=>$issue,'number_key'=>$number])->value('used_amount');
}

function allocateContext(array $user,array $lottery,array $odds,string $issue,int $recordId,string $numberText='123',float $amount=100): array
{
    return ['tenant_id'=>(int)$user['tenant_id'],'site_id'=>(int)$user['site_id'],'user_id'=>(int)$user['id'],'lottery_id'=>(int)$lottery['id'],'issue_no'=>$issue,'bet_record_id'=>$recordId,'bet_detail_id'=>$recordId,'number_text'=>$numberText,'amount'=>$amount,'odds'=>$odds];
}

$allocator=new InterceptionAllocator();
$duplicateNumberText='660 661 662 663 664 665 666 667 668 668';
$stakePlanner=new ReflectionMethod($allocator,'stakes');$stakePlanner->setAccessible(true);
$stakePlan=$stakePlanner->invoke($allocator,$duplicateNumberText,600.0);
$stakeTotals=[];
foreach($stakePlan as $stakeEntry){
    if(abs((float)$stakeEntry['amount']-60.0)>0.001)throw new RuntimeException('600元10次投注的每次金额应为60');
    $number=(string)$stakeEntry['number'];$stakeTotals[$number]=($stakeTotals[$number]??0)+(float)$stakeEntry['amount'];
}
if(count($stakePlan)!==10||abs((float)($stakeTotals['660']??0)-60.0)>0.001||abs((float)($stakeTotals['668']??0)-120.0)>0.001)throw new RuntimeException('重复号码的纯金额分配不正确');

$agentA=Db::name('organization_nodes')->where('level','agent')->where('status',1)->whereNull('deleted_at')->find();
$userTemplate=$agentA?Db::name('site_users')->where('organization_id',(int)$agentA['id'])->whereNull('deleted_at')->find():null;
$lottery=Db::name('lotteries')->where('code','fc3d')->where('status',1)->whereNull('deleted_at')->find();
$odds=$lottery?Db::name('lottery_odds')->where('lottery_id',(int)$lottery['id'])->where('status',1)->whereNull('deleted_at')->find():null;
if(!$agentA||!$userTemplate||!$lottery||!$odds){echo "InterceptionAllocator pure tests passed: duplicate=60/120; integration skipped: missing fixtures\n";exit(0);}
$tenantId=(int)$agentA['tenant_id'];$siteId=(int)$agentA['site_id'];$lotteryId=(int)$lottery['id'];$oddsId=(int)$odds['id'];
$odds['platform_single_item_limit']='50.00';
$suffix=bin2hex(random_bytes(4));$now=date('Y-m-d H:i:s');

Db::startTrans();
try{
    $agentB=$agentA;unset($agentB['id']);$agentB['name']='拦货测试代理B-'.$suffix;$agentB['code']='AG-TEST-'.$suffix;$agentB['settings']=interceptionSettings($lotteryId,$oddsId,100,false);$agentB['created_at']=$now;$agentB['updated_at']=$now;
    $agentBId=(int)Db::name('organization_nodes')->insertGetId($agentB);
    Db::name('organization_nodes')->where('id',$agentBId)->update(['path'=>(string)$agentA['path'].$agentBId.'/']);
    Db::name('organization_nodes')->where('id',(int)$agentA['id'])->update(['settings'=>interceptionSettings($lotteryId,$oddsId,100,false),'updated_at'=>$now]);

    $makeUser=static function(array $template,int $organizationId,string $username)use($now):array{
        unset($template['id']);$template['organization_id']=$organizationId;$template['username']=$username;$template['display_name']=$username;$template['interception_rate']='100.0000';$template['balance']='0.00';$template['credit_balance']='10000.00';$template['used_balance']='0.00';$template['created_at']=$now;$template['updated_at']=$now;$template['deleted_at']=null;
        $id=(int)Db::name('site_users')->insertGetId($template);return Db::name('site_users')->where('id',$id)->find();
    };
    $userA=$makeUser($userTemplate,(int)$agentA['id'],'intercept-a-'.$suffix);$userB=$makeUser($userTemplate,$agentBId,'intercept-b-'.$suffix);

    $settingKey='agent_interception_amounts_'.$lotteryId;$legacy=Db::name('settings')->where('site_id',$siteId)->where('key',$settingKey)->find();
    if($legacy)Db::name('settings')->where('id',(int)$legacy['id'])->update(['value'=>json_encode([(string)$oddsId=>'1']),'updated_at'=>$now]);
    else Db::name('settings')->insert(['tenant_id'=>$tenantId,'site_id'=>$siteId,'key'=>$settingKey,'value'=>json_encode([(string)$oddsId=>'1']),'updated_at'=>$now]);

    $isolationIssue='ORG-INDEPENDENT-'.$suffix;
    $a=$allocator->allocate(allocateContext($userA,$lottery,$odds,$isolationIssue,910001));
    $b=$allocator->allocate(allocateContext($userB,$lottery,$odds,$isolationIssue,910002));
    if((float)($a[0]['intercepted']??0)!==100.0||(float)($b[0]['intercepted']??0)!==100.0)throw new RuntimeException('同站点两个代理未能各自独立拦100');
    if(usage($tenantId,'organization',(int)$agentA['id'],$lotteryId,$isolationIssue,$oddsId,'123')!==100.0||usage($tenantId,'organization',$agentBId,$lotteryId,$isolationIssue,$oddsId,'123')!==100.0)throw new RuntimeException('两个代理的组织容量作用域未隔离');
    if(($a[0]['configuration_source']??'')!=='organization'||($b[0]['configuration_source']??'')!=='organization')throw new RuntimeException('组织配置被旧站点配置覆盖');

    $numberParser=new ReflectionMethod($allocator,'numbers');$numberParser->setAccessible(true);
    $parsedNumbers=$numberParser->invoke($allocator,$duplicateNumberText);
    if(count($parsedNumbers)!==10||count(array_filter($parsedNumbers,static fn(string $number):bool=>$number==='668'))!==2)throw new RuntimeException('拦货号码解析不得去除重复投注次数');

    Db::name('organization_nodes')->where('id',(int)$agentA['id'])->update(['settings'=>interceptionSettings($lotteryId,$oddsId,1000,false),'updated_at'=>$now]);
    $duplicateIssue='DUPLICATE-STAKES-'.$suffix;$duplicateRecord=910006;
    $duplicate=$allocator->allocate(allocateContext($userA,$lottery,$odds,$duplicateIssue,$duplicateRecord,$duplicateNumberText,600));
    $duplicateRows=Db::name('agent_interceptions')->where('bet_record_id',$duplicateRecord)->order('id')->select()->toArray();
    if(count($duplicate)!==10||count($duplicateRows)!==10)throw new RuntimeException('600元10次投注应生成10条拦货记录');
    foreach($duplicateRows as $row)if(abs((float)$row['bet_amount']-60.0)>0.001)throw new RuntimeException('每次投注的拦货金额应为60');
    if(usage($tenantId,'organization',(int)$agentA['id'],$lotteryId,$duplicateIssue,$oddsId,'660')!==60.0)throw new RuntimeException('普通号660的拦货累计应为60');
    if(usage($tenantId,'organization',(int)$agentA['id'],$lotteryId,$duplicateIssue,$oddsId,'668')!==120.0)throw new RuntimeException('重复号668的两次拦货累计应为120');
    Db::name('organization_nodes')->where('id',(int)$agentA['id'])->update(['settings'=>interceptionSettings($lotteryId,$oddsId,100,false),'updated_at'=>$now]);

    Db::name('organization_nodes')->where('id',$agentBId)->update(['settings'=>interceptionSettings($lotteryId,$oddsId,5,true)]);
    $configIssue='CONFIG-A-'.$suffix;$configA=$allocator->allocate(allocateContext($userA,$lottery,$odds,$configIssue,910003));
    if((float)($configA[0]['intercepted']??0)!==100.0)throw new RuntimeException('组织B或旧站点配置影响了组织A');
    if(usage($tenantId,'platform',$tenantId,$lotteryId,$configIssue,$oddsId,'123')!==0.0)throw new RuntimeException('follow=false 错误占用了平台容量');

    Db::name('organization_nodes')->where('id',(int)$agentA['id'])->update(['settings'=>interceptionSettings($lotteryId,$oddsId,100,true)]);
    $followIssue='FOLLOW-'.$suffix;$followRecord=910004;$follow=$allocator->allocate(allocateContext($userA,$lottery,$odds,$followIssue,$followRecord));
    if((float)($follow[0]['intercepted']??0)!==50.0||($follow[0]['status']??'')!=='platform_partial')throw new RuntimeException('follow=true 未缩减到平台实收50');
    if(usage($tenantId,'organization',(int)$agentA['id'],$lotteryId,$followIssue,$oddsId,'123')!==50.0||usage($tenantId,'platform',$tenantId,$lotteryId,$followIssue,$oddsId,'123')!==50.0)throw new RuntimeException('follow=true 的代理/平台容量使用不一致');

    $allocator->releaseForRecord($followRecord);
    if(usage($tenantId,'organization',(int)$agentA['id'],$lotteryId,$followIssue,$oddsId,'123')!==0.0||usage($tenantId,'platform',$tenantId,$lotteryId,$followIssue,$oddsId,'123')!==0.0)throw new RuntimeException('退款未释放原组织和平台容量');
    $releasedAt=(string)Db::name('agent_interceptions')->where('bet_record_id',$followRecord)->value('released_at');$allocator->releaseForRecord($followRecord);
    if((string)Db::name('agent_interceptions')->where('bet_record_id',$followRecord)->value('released_at')!==$releasedAt)throw new RuntimeException('重复退款再次修改了释放记录');

    $token='interception-test-'.$suffix;
    Cache::set('token:'.$token,['scope'=>'agent','tenant_id'=>$tenantId,'site_id'=>$siteId,'organization_id'=>(int)$agentA['id'],'user_id'=>0,'username'=>'test','permissions'=>['*']],60);
    $request=(new think\Request())->withHeader(['authorization'=>'Bearer '.$token])->withGet(['lottery'=>(string)$lottery['name'],'issue_no'=>$configIssue]);
    $plate=json_decode((new app\controller\AgentInterception())->plate($request)->getContent(),true)['data']??[];Cache::delete('token:'.$token);
    $plateItem=null;foreach($plate['groups']??[] as $group)foreach($group['items']??[] as $item)if((int)$item['odds_id']===$oddsId)$plateItem=$item;
    if(($plate['capacity_scope']??'')!=='organization'||(int)($plate['organization_id']??0)!==(int)$agentA['id']||($plate['configuration_source']??'')!=='organization')throw new RuntimeException('拦货盘未使用当前代理组织作用域和配置');
    if(!$plateItem||(float)$plateItem['limit']!==100.0||(float)$plateItem['used']!==100.0||(float)$plateItem['remaining']!==0.0)throw new RuntimeException('拦货盘 limit/used/remaining 与真实 allocator 容量不一致');

    $rollbackIssue='ROLLBACK-'.$suffix;$rollbackRecord=910005;
    try{Db::transaction(function()use($allocator,$userA,$lottery,$odds,$rollbackIssue,$rollbackRecord):void{$allocator->allocate(allocateContext($userA,$lottery,$odds,$rollbackIssue,$rollbackRecord));throw new RuntimeException('force rollback');});}
    catch(RuntimeException $error){if($error->getMessage()!=='force rollback')throw $error;}
    if(Db::name('agent_interceptions')->where('bet_record_id',$rollbackRecord)->count()>0||Db::name('interception_capacity_usage')->where('issue_no',$rollbackIssue)->count()>0)throw new RuntimeException('下注事务失败后残留拦货或容量数据');

    Db::rollback();
    echo "InterceptionAllocator tests passed: independent=100/100, duplicate=60/120, follow_off=100, follow_on=50, release=0, rollback=clean\n";
}catch(Throwable $error){Db::rollback();throw $error;}
