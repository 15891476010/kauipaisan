<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));
$app->initialize();

$scheduler=new app\service\RobotScheduler();
$method=new ReflectionMethod($scheduler,'poolDailyTarget');
$method->setAccessible(true);
$pool=['director_id'=>314159,'user_ids'=>[1,2,3]];
$day=strtotime('2026-09-21 12:00:00');
$first=(float)$method->invoke($scheduler,$pool,$day);
$second=(float)$method->invoke($scheduler,$pool,$day);
if($first<13000000||$first>17000000) throw new RuntimeException('每日总投目标超出 1300万~1700万');
if($first!==$second) throw new RuntimeException('同一总监同一天的总投目标必须稳定');
$pendingToday=new ReflectionMethod($scheduler,'pendingIssueIsToday');
$pendingToday->setAccessible(true);
if(!$pendingToday->invoke($scheduler,['open_time'=>'2026-09-21 21:25:00'],$day)) throw new RuntimeException('追单到今天未开奖期号时必须切回实时打单');
if($pendingToday->invoke($scheduler,['open_time'=>'2026-09-22 21:25:00'],$day)) throw new RuntimeException('未来日期的未开奖期号不得提前切回实时打单');
$source=file_get_contents(dirname(__DIR__).'/app/service/RobotScheduler.php');
if(str_contains($source,'monthlyConfig(')||str_contains($source,'weeklyDealerProfit(')) throw new RuntimeException('调度器仍含周/月盈亏控盘入口');
if(!str_contains($source,'$wantWin=null;')) throw new RuntimeException('机器人未明确恢复普通随机选号');
echo "RobotDailyTargetTest passed: target={$first}\n";
