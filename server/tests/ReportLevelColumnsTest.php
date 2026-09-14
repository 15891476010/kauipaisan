<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app = new think\App(dirname(__DIR__));
$app->initialize();

use app\controller\AgentReport;
use app\service\OrganizationHierarchy;
use think\Request;
use think\facade\Cache;
use think\facade\Db;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function decoded($response): array
{
    $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    check(($payload['code'] ?? -1) === 0, 'Controller returned an error: '.($payload['message'] ?? ''));
    return $payload['data'] ?? [];
}

$site = Db::name('sites')->whereNull('deleted_at')->field('id,tenant_id')->find();
if (!$site) throw new RuntimeException('A test site is required');

$prefix = 'rptlvl_'.bin2hex(random_bytes(4));
$token = bin2hex(random_bytes(24));
$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');
$siteId = (int)$site['id'];
$tenantId = (int)$site['tenant_id'];
$nodeIds = [];
$userIds = [];

$makeNode = function(int $parentId, string $level) use ($prefix, $now, $siteId, $tenantId, &$nodeIds): int {
    $id = (int)Db::name('organization_nodes')->insertGetId([
        'tenant_id'=>$tenantId, 'site_id'=>$siteId, 'parent_id'=>$parentId, 'level'=>$level,
        'name'=>$prefix.'_'.count($nodeIds), 'code'=>$prefix.'_'.count($nodeIds),
        'path'=>'/', 'depth'=>1, 'credit_limit'=>0, 'balance'=>0,
        'permissions'=>'["*"]', 'settings'=>'{}',
        'status'=>1, 'created_at'=>$now, 'updated_at'=>$now,
    ]);
    $nodeIds[] = $id;
    OrganizationHierarchy::rebuildPath($id);
    return $id;
};
$makeUser = function(int $organizationId, string $name) use ($now, $siteId, $tenantId, &$userIds): int {
    $id = (int)Db::name('site_users')->insertGetId([
        'tenant_id'=>$tenantId, 'site_id'=>$siteId, 'organization_id'=>$organizationId,
        'username'=>$name, 'display_name'=>$name, 'password'=>password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT),
        'balance'=>'0.00', 'credit_balance'=>'0.00', 'used_balance'=>'0.00', 'status'=>1,
        'created_at'=>$now, 'updated_at'=>$now,
    ]);
    $userIds[] = $id;
    return $id;
};
$makeShare = function(int $childId, int $parentId, float $rate) use ($now, $siteId, $tenantId): void {
    Db::name('organization_profit_shares')->insert([
        'tenant_id'=>$tenantId, 'site_id'=>$siteId,
        'parent_organization_id'=>$parentId, 'child_organization_id'=>$childId,
        'max_share_rate'=>100, 'share_rate'=>$rate, 'status'=>1,
        'created_at'=>$now, 'updated_at'=>$now,
    ]);
};
$makeBet = function(int $userId, float $amount, float $win) use ($now, $siteId, $tenantId): void {
    $recordId = (int)Db::name('bet_records')->insertGetId([
        'tenant_id'=>$tenantId, 'site_id'=>$siteId, 'user_id'=>$userId,
        'issue_no'=>'RPTLVL001', 'status'=>$win > 0 ? 'won' : 'unwon',
        'amount'=>number_format($amount, 2, '.', ''), 'win_amount'=>number_format($win, 2, '.', ''),
        'bet_count'=>1, 'placed_at'=>$now, 'created_at'=>$now,
    ]);
    Db::name('bet_details')->insert([
        'tenant_id'=>$tenantId, 'site_id'=>$siteId, 'user_id'=>$userId, 'bet_record_id'=>$recordId,
        'issue_no'=>'RPTLVL001', 'number_text'=>'123', 'amount'=>number_format($amount, 2, '.', ''),
        'odds'=>'9.9', 'win_amount'=>number_format($win, 2, '.', ''), 'rebate'=>'0.00',
        'status'=>$win > 0 ? 'won' : 'unwon', 'placed_at'=>$now,
    ]);
};

Db::startTrans();
try {
    $root = $makeNode(0, 'director');
    // Branch A: agent opened directly under the director — the member's chain
    // has no 总代理/股东 level at all.
    $agentA = $makeNode($root, 'agent');
    $makeShare($agentA, $root, 100);
    $memberA = $makeUser($agentA, $prefix.'_a');
    $makeBet($memberA, 1000, 0); // member loses 1000

    // Branch B: agent sits under a 总代理 but keeps 100% — the mid level is in
    // the chain yet books nothing.
    $general = $makeNode($root, 'general_agent');
    $makeShare($general, $root, 50);
    $agentB = $makeNode($general, 'agent');
    $makeShare($agentB, $general, 100);
    $memberB = $makeUser($agentB, $prefix.'_b');
    $makeBet($memberB, 1000, 0);

    $session = ['scope'=>'agent', 'site_id'=>$siteId, 'tenant_id'=>$tenantId, 'organization_id'=>$root, 'user_id'=>0, 'username'=>'report-level-test'];
    Cache::set('token:'.$token, $session, 300);
    $request = (new Request())->withHeader(['authorization'=>'Bearer '.$token])->withGet(['from'=>$today, 'to'=>$today]);
    $request->setMethod('GET');

    $data = decoded((new AgentReport())->index($request));
    $list = $data['list'] ?? [];
    $byMember = [];
    foreach ($list as $memberRow) $byMember[(string)$memberRow['member']] = $memberRow['summary'];

    // Member A: chain is member -> agent -> director. The agent booked the
    // full 1000 loss as +1000 profit; 总代理/股东 columns must stay zero.
    $a = $byMember[$prefix.'_a'] ?? null;
    check($a !== null, 'Member A row missing');
    check($a['member_profit'] === '-1000', 'Member A profit must be -1000, got '.var_export($a['member_profit'], true));
    check(($a['levels']['agent']['amount'] ?? null) === '1000', 'Agent level amount for A must be 1000, got '.var_export($a['levels']['agent']['amount'] ?? null, true));
    check(($a['levels']['agent']['profit'] ?? null) === '1000', 'Agent level profit for A must be +1000 (mirror), got '.var_export($a['levels']['agent']['profit'] ?? null, true));
    check(($a['levels']['director']['profit'] ?? null) === '0', 'Director remainder for A must be 0, got '.var_export($a['levels']['director']['profit'] ?? null, true));
    check(!isset($a['levels']['general_agent']), 'A: general_agent must be absent (not in chain)');
    check(!isset($a['levels']['shareholder']) && !isset($a['levels']['small_shareholder']), 'A: shareholder levels must be absent (not in chain)');

    // Member B: chain is member -> agent -> 总代理 -> director. The agent
    // keeps 100%, so the in-chain 总代理 shows the stake but zero profit.
    $b = $byMember[$prefix.'_b'] ?? null;
    check($b !== null, 'Member B row missing');
    check(($b['levels']['agent']['profit'] ?? null) === '1000', 'Agent level profit for B must be +1000, got '.var_export($b['levels']['agent']['profit'] ?? null, true));
    check(($b['levels']['general_agent']['amount'] ?? null) === '1000', 'In-chain 总代理 must show stake 1000, got '.var_export($b['levels']['general_agent']['amount'] ?? null, true));
    check(($b['levels']['general_agent']['profit'] ?? null) === '0', 'In-chain saturated 总代理 profit must be 0, got '.var_export($b['levels']['general_agent']['profit'] ?? null, true));

    // Aggregated summary: agent level totals both branches, mid level only B.
    $summary = $data['summary'] ?? [];
    check(($summary['levels']['agent']['profit'] ?? null) === '2000', 'Summary agent profit must be 2000, got '.var_export($summary['levels']['agent']['profit'] ?? null, true));
    check(($summary['levels']['general_agent']['amount'] ?? null) === '1000', 'Summary 总代理 amount must be 1000 (branch B only), got '.var_export($summary['levels']['general_agent']['amount'] ?? null, true));
    check(!isset($summary['levels']['shareholder']), 'Summary must not contain levels absent from every chain');

    // The viewer (director) self figures remain the share-based columns.
    check(($summary['share_amount'] ?? null) === '0', 'Viewer share amount must be 0 after a 100% agent, got '.var_export($summary['share_amount'] ?? null, true));
} finally {
    Db::rollback();
    Cache::delete('token:'.$token);
}
check(Db::name('organization_nodes')->whereIn('id', $nodeIds)->count() === 0, 'Fixture rollback failed');
echo "Report level column tests passed; fixtures rolled back\n";
