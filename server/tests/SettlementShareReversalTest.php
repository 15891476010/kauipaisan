<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app = new think\App(dirname(__DIR__));
$app->initialize();

use app\controller\AdminBetBatch;
use app\service\CreditLedger;
use app\service\OrganizationHierarchy;
use think\facade\Db;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$site = Db::name('sites')->whereNull('deleted_at')->field('id,tenant_id')->find();
if (!$site) throw new RuntimeException('A test site is required');
$siteId = (int)$site['id'];
$tenantId = (int)$site['tenant_id'];
$prefix = 'shrrev_'.bin2hex(random_bytes(4));
$now = date('Y-m-d H:i:s');

// recordedMovement reports the actual stored balance movement, which is the
// only amount a rollback may reverse.
check(CreditLedger::recordedMovement(['balance_before'=>1000,'balance_after'=>400,'amount'=>600,'direction'=>'out']) === -600.0, 'moved row must report -600');
check(CreditLedger::recordedMovement(['balance_before'=>-693.12,'balance_after'=>-693.12,'amount'=>1488,'direction'=>'out']) === 0.0, 'bookkeeping-only row must report 0');
check(CreditLedger::recordedMovement([]) === 0.0, 'missing balances must report 0');

$nodeId = 0; $userId = 0; $recordId = 0; $ledgerIds = [];
Db::startTrans();
try {
    $nodeId = (int)Db::name('organization_nodes')->insertGetId([
        'tenant_id'=>$tenantId,'site_id'=>$siteId,'parent_id'=>0,'level'=>'agent',
        'name'=>$prefix,'code'=>$prefix,'path'=>'/','depth'=>0,
        'credit_limit'=>0,'balance'=>1000,'permissions'=>'["*"]','settings'=>'{}',
        'status'=>1,'created_at'=>$now,'updated_at'=>$now,
    ]);
    OrganizationHierarchy::rebuildPath($nodeId);
    $userId = (int)Db::name('site_users')->insertGetId([
        'tenant_id'=>$tenantId,'site_id'=>$siteId,'organization_id'=>$nodeId,
        'username'=>$prefix,'display_name'=>$prefix,'password'=>password_hash($prefix,PASSWORD_DEFAULT),
        'balance'=>500,'credit_balance'=>0,'used_balance'=>0,'status'=>1,
        'created_at'=>$now,'updated_at'=>$now,
    ]);
    $recordId = (int)Db::name('bet_records')->insertGetId([
        'tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$userId,
        'issue_no'=>'TEST001','status'=>'won','amount'=>100,'win_amount'=>600,
        'placed_at'=>$now,'created_at'=>$now,
    ]);
    $write = static function(float $amount,string $direction,float $before,float $after) use ($recordId,$nodeId,$userId,$siteId,$tenantId,$now,&$ledgerIds): void {
        $ledgerIds[] = (int)Db::name('organization_credit_ledger')->insertGetId([
            'transaction_no'=>'TEST'.bin2hex(random_bytes(6)),'tenant_id'=>$tenantId,'site_id'=>$siteId,
            'organization_id'=>$nodeId,'account_type'=>'organization','account_id'=>$nodeId,
            'related_user_id'=>$userId,'related_bet_record_id'=>$recordId,'issue_no'=>'TEST001',
            'direction'=>$direction,'amount'=>number_format(abs($amount),2,'.',''),
            'balance_before'=>number_format($before,2,'.',''),'balance_after'=>number_format($after,2,'.',''),
            'reason'=>'本期投注亏损承担','source_type'=>'settlement_share','category'=>'settlement',
            'created_at'=>$now,
        ]);
    };
    // Legacy-era share: the node balance really dropped from 1000 to 400.
    $write(600, 'out', 1000, 400);
    // Current-era shares: bookkeeping only, the balance never moved.
    $write(999999, 'out', 400, 400);
    $write(50, 'in', 400, 400);

    Db::name('organization_nodes')->where('id',$nodeId)->update(['balance'=>400,'updated_at'=>$now]);

    $controller = new AdminBetBatch();
    $method = new ReflectionMethod($controller, 'reopenSettledRecord');
    $method->setAccessible(true);
    $record = Db::name('bet_records')->where('id',$recordId)->find();
    $method->invoke($controller, $record);

    // Only the real -600 movement is reversed; the bookkeeping rows
    // (amount 999999 / 50, unchanged balances) must be ignored entirely.
    $balance = (float)Db::name('organization_nodes')->where('id',$nodeId)->value('balance');
    check(abs($balance-1000)<0.001, 'Reopen must restore only the recorded movement, got '.$balance);

    echo "Settlement share reversal tests passed; fixtures rolled back\n";
} finally {
    Db::rollback();
}
