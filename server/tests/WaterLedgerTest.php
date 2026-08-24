<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));
$app->initialize();

use app\service\WaterLedger;
use think\facade\Db;

$assertions=0;
$check=static function(bool $condition,string $message)use(&$assertions):void{$assertions++;if(!$condition)throw new RuntimeException($message);};
$check(WaterLedger::calculate(100,0.01)['amount']===1.0,'100 × 0.01 应为 1.00');
$check(WaterLedger::calculate(216,0.005)['amount']===1.08,'216 × 0.005 应为 1.08');
$check(WaterLedger::calculate(10.005,0.01)['base_amount']===10.01,'基础金额应按分币四舍五入');
$check(WaterLedger::calculate(100,0)['amount']===0.0,'无赚水比例不得产生流水金额');
$check(Db::query("SHOW TABLES LIKE 'organization_water_ledger'")!==[],'赚水独立流水表不存在');
$duplicates=Db::name('organization_water_ledger')->field('related_bet_detail_id,organization_id,source_type,COUNT(*) AS duplicate_count')->group('related_bet_detail_id,organization_id,source_type')->having('duplicate_count>1')->select()->toArray();
$check($duplicates===[],'赚水流水出现同明细重复记录');
echo "Water ledger tests passed: {$assertions} assertions\n";
