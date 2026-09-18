<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app = new think\App(dirname(__DIR__));
$app->initialize();

use app\controller\AgentReport;
use app\service\OrganizationHierarchy;
use app\service\ReportMaterializer;
use think\Request;
use think\facade\Cache;
use think\facade\Db;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$site = Db::name('sites')->whereNull('deleted_at')->field('id,tenant_id')->find();
if (!$site) throw new RuntimeException('A development test site is required');
$siteId = (int)$site['id'];
$tenantId = (int)$site['tenant_id'];
$prefix = 'rptmat_'.bin2hex(random_bytes(4));
$token = bin2hex(random_bytes(24));
$now = date('Y-m-d H:i:s');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$nodeIds = [];
$makeNode = static function(int $parent, string $level, string $name) use ($siteId, $tenantId, $prefix, $now, &$nodeIds): int {
    $id = (int)Db::name('organization_nodes')->insertGetId([
        'tenant_id'=>$tenantId,'site_id'=>$siteId,'parent_id'=>$parent,'level'=>$level,'name'=>$name,
        'code'=>$prefix.count($nodeIds),'path'=>'/','depth'=>1,'permissions'=>'["*"]','settings'=>'{}',
        'status'=>1,'created_at'=>$now,'updated_at'=>$now,
    ]);
    $nodeIds[] = $id;
    OrganizationHierarchy::rebuildPath($id);
    return $id;
};
$makeUser = static function(int $org, string $name) use ($siteId, $tenantId, $now): int {
    return (int)Db::name('site_users')->insertGetId([
        'tenant_id'=>$tenantId,'site_id'=>$siteId,'organization_id'=>$org,'username'=>$name,'display_name'=>$name,
        'password'=>password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),'status'=>1,'created_at'=>$now,'updated_at'=>$now,
    ]);
};
$makeBet = static function(int $user, string $issue, string $lottery, float $amount, string $status, string $placedAt) use ($siteId, $tenantId): int {
    $record = (int)Db::name('bet_records')->insertGetId([
        'tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$user,'issue_no'=>$issue,'lottery_name'=>$lottery,'status'=>$status,
        'amount'=>$amount,'win_amount'=>$status==='won'?$amount*2:0,'bet_count'=>1,'placed_at'=>$placedAt,'created_at'=>$placedAt,
    ]);
    Db::name('bet_details')->insert([
        'tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$user,'bet_record_id'=>$record,'issue_no'=>$issue,
        'lottery_name'=>$lottery,'number_text'=>'123','amount'=>$amount,'win_amount'=>$status==='won'?$amount*2:0,'rebate'=>0,'odds'=>1,'status'=>$status,'placed_at'=>$placedAt,
    ]);
    return $record;
};
$controller = new AgentReport();
$call = static function(array $params = []) use ($controller, $token, $yesterday): array {
    $request = (new Request())->withHeader(['authorization'=>'Bearer '.$token])->withGet(array_merge(['from'=>$yesterday,'to'=>$yesterday], $params));
    $request->setMethod('GET');
    return json_decode($controller->index($request)->getContent(), true, 512, JSON_THROW_ON_ERROR);
};
Db::startTrans();
try {
    $root = $makeNode(0, 'director', '物化总监');
    $agent = $makeNode($root, 'agent', '物化代理');
    $user = $makeUser($agent, $prefix.'m');
    // Yesterday's bets go through the materialized path once refreshed.
    $makeBet($user, $prefix.'i1', '福彩3D', 100, 'won', $yesterday.' 10:00:00');
    $makeBet($user, $prefix.'i2', '排列三', 50, 'unwon', $yesterday.' 11:00:00');
    $makeBet($user, $prefix.'i3', '福彩3D', 30, 'pending', $yesterday.' 12:00:00');
    $session = ['scope'=>'agent','site_id'=>$siteId,'tenant_id'=>$tenantId,'organization_id'=>$root,'user_id'=>0,'username'=>$prefix];
    Cache::set('token:'.$token, $session, 300);

    $materializer = new ReportMaterializer();
    $materializer->refreshDay($siteId, $yesterday, $tenantId);
    $stored = Db::name('report_member_issue')->where('site_id', $siteId)->where('user_id', $user)->select()->toArray();
    check(count($stored) === 3, 'Persisted rows must match the member day groups');
    $lotteries = array_unique(array_column($stored, 'lottery_name'));
    check(in_array('福彩3D', $lotteries, true) && in_array('排列三', $lotteries, true), 'Lottery names must persist for filtering');

    $data = $call()['data'];
    $row = array_values(array_filter($data['list'], static fn($item) => $item['member'] === $prefix.'m'))[0] ?? null;
    check($row !== null, 'Materialized member row is missing');
    check($row['summary']['amount'] === '180', 'Materialized amount must sum all three groups');
    check($row['issue_count'] === 3, 'Materialized issue count must count each issue once');
    check(array_column($data['chain_levels'], 'key') === ['member','small_shareholder','shareholder','director'], 'Director chain columns are wrong');
    check($row['chain']['director']['name'] === '物化总监', 'Materialized view must keep chain labels');

    $filtered = $call(['lotteries'=>'福彩3D'])['data'];
    $frow = array_values(array_filter($filtered['list'], static fn($item) => $item['member'] === $prefix.'m'))[0];
    check($frow['summary']['amount'] === '130', 'Lottery filter must apply to materialized rows');
    check($frow['issue_count'] === 2, 'Lottery filter must narrow the issue count');

    // Incremental refresh picks up a new bet for the same day.
    $makeBet($user, $prefix.'i4', '福彩3D', 20, 'unwon', $yesterday.' 13:00:00');
    $changed = $materializer->refreshChangedDays($siteId, $tenantId);
    check(isset($changed[$yesterday]), 'Changed-day refresh must rebuild the touched day');
    check(Db::name('report_member_issue')->where('site_id', $siteId)->where('user_id', $user)->count() === 4, 'Incremental refresh must persist the new group');
    $again = $call()['data'];
    $arow = array_values(array_filter($again['list'], static fn($item) => $item['member'] === $prefix.'m'))[0];
    check($arow['summary']['amount'] === '200', 'Incremental refresh must surface the new bet');
} finally {
    Db::rollback();
    Cache::delete('token:'.$token);
}
echo "Report materialize tests passed; fixtures rolled back\n";
