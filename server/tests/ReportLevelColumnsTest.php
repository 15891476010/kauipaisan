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
    // Both lotteries share the same issue code — the report's history join
    // fans out to two rows per detail and must not double-count amounts.
    foreach ([1, 2] as $lotteryId) {
        Db::name('lottery_histories')->insert([
            'tenant_id'=>$tenantId, 'lottery_id'=>$lotteryId, 'code'=>'RPTLVL001',
            'draw_day'=>$today, 'numbers'=>'123', 'is_opened'=>1,
            'created_at'=>$now, 'updated_at'=>$now,
        ]);
    }

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

    // Branch C: a settled record whose allocation snapshot was booked at 100%
    // share. The live share was later cut to 80% — the report must keep the
    // booked snapshot, not recompute at the new rate.
    $agentC = $makeNode($root, 'agent');
    $makeShare($agentC, $root, 80); // live rate is now 80%
    $memberC = $makeUser($agentC, $prefix.'_c');
    $recordC = (int)Db::name('bet_records')->insertGetId([
        'tenant_id'=>$tenantId, 'site_id'=>$siteId, 'user_id'=>$memberC,
        'issue_no'=>'RPTLVL001', 'status'=>'unwon',
        'amount'=>'1000.00', 'win_amount'=>'0.00',
        'bet_count'=>1, 'placed_at'=>$now, 'created_at'=>$now,
    ]);
    Db::name('bet_details')->insert([
        'tenant_id'=>$tenantId, 'site_id'=>$siteId, 'user_id'=>$memberC, 'bet_record_id'=>$recordC,
        'issue_no'=>'RPTLVL001', 'number_text'=>'456', 'amount'=>'1000.00',
        'odds'=>'9.9', 'win_amount'=>'0.00', 'rebate'=>'0.00',
        'status'=>'unwon', 'placed_at'=>$now,
    ]);
    // Settlement-time snapshot: the agent booked the full 1000 at 100%.
    Db::name('organization_credit_ledger')->insert([
        'transaction_no'=>'TEST'.bin2hex(random_bytes(6)), 'tenant_id'=>$tenantId, 'site_id'=>$siteId,
        'organization_id'=>$agentC, 'account_type'=>'organization', 'account_id'=>$agentC,
        'related_user_id'=>$memberC, 'related_bet_record_id'=>$recordC, 'issue_no'=>'RPTLVL001',
        'direction'=>'in', 'amount'=>'1000.00', 'balance_before'=>'0.00', 'balance_after'=>'0.00',
        'reason'=>'本期投注盈利占成', 'source_type'=>'settlement_share', 'category'=>'settlement',
        'metadata'=>json_encode(['organization_level'=>'agent', 'share_rate'=>100.0, 'share_amount'=>1000.0]),
        'created_at'=>$now,
    ]);

    $session = ['scope'=>'agent', 'site_id'=>$siteId, 'tenant_id'=>$tenantId, 'organization_id'=>$root, 'user_id'=>0, 'username'=>'report-level-test'];
    Cache::set('token:'.$token, $session, 300);
    $request = (new Request())->withHeader(['authorization'=>'Bearer '.$token])->withGet(['from'=>$today, 'to'=>$today]);
    $request->setMethod('GET');

    $data = decoded((new AgentReport())->index($request));
    $byMember = [];
    foreach ([$agentA, $agentB, $agentC] as $target) {
        $memberRequest = (new Request())->withHeader(['authorization'=>'Bearer '.$token])->withGet(['from'=>$today, 'to'=>$today, 'organization_id'=>$target]);
        $memberRequest->setMethod('GET');
        $list = decoded((new AgentReport())->index($memberRequest))['list'] ?? [];
        foreach ($list as $memberRow) $byMember[(string)$memberRow['member']] = $memberRow['summary'];
    }

    // Member A: chain is member -> agent -> director. The agent's 占成金额 is
    // 100% of the 1000 turnover; 占成盈亏 = 1000 − 8.5%×1000 = 915. Its own
    // level lives in the self columns, so the upline column carries the
    // residual book arriving at the agent (full 1000, result 915 after water).
    $a = $byMember[$prefix.'_a'] ?? null;
    check($a !== null, 'Member A row missing');
    check($a['member_profit'] === '-1000', 'Member A profit must be -1000, got '.var_export($a['member_profit'], true));
    check($a['amount'] === '1000', 'Member A amount must count once (join fan-out), got '.var_export($a['amount'], true));
    check(($a['share_amount'] ?? null) === '1000', 'A: viewer share amount must be 1000 (100%×总投), got '.var_export($a['share_amount'] ?? null, true));
    check(($a['share_profit'] ?? null) === '915', 'A: viewer share profit must be 915 (1000 − 85 water), got '.var_export($a['share_profit'] ?? null, true));
    check(($a['agent_profit'] ?? null) === '0', 'A: agent income must be 0 (no org children), got '.var_export($a['agent_profit'] ?? null, true));
    check(($a['offline_water'] ?? null) === '0', 'A: offline water must be 0 for a leaf viewer, got '.var_export($a['offline_water'] ?? null, true));
    check(($a['levels']['general_agent']['amount'] ?? null) === '1000', 'A: upline residual turnover must be 1000, got '.var_export($a['levels']['general_agent']['amount'] ?? null, true));
    check(($a['levels']['general_agent']['profit'] ?? null) === '915', 'A: upline residual profit must be 915 (1000 − 85 water), got '.var_export($a['levels']['general_agent']['profit'] ?? null, true));
    check(!isset($a['levels']['agent']), 'A: the viewer level must not appear in level columns');
    check(!isset($a['levels']['shareholder']) && !isset($a['levels']['small_shareholder']), 'A: shareholder levels must be absent (not in chain)');

    // Member B: chain is member -> agent -> 总代理 -> director. From the
    // agent's own view the residual (full book, agent takes nothing above
    // itself) still flows to the 总代理 column.
    $b = $byMember[$prefix.'_b'] ?? null;
    check($b !== null, 'Member B row missing');
    check(($b['share_amount'] ?? null) === '1000', 'B: viewer share amount must be 1000, got '.var_export($b['share_amount'] ?? null, true));
    check(($b['share_profit'] ?? null) === '915', 'B: viewer share profit must be 915, got '.var_export($b['share_profit'] ?? null, true));
    check(($b['levels']['general_agent']['amount'] ?? null) === '1000', 'B: upline residual turnover must be 1000, got '.var_export($b['levels']['general_agent']['amount'] ?? null, true));
    check(($b['levels']['general_agent']['profit'] ?? null) === '915', 'B: upline residual profit must be 915, got '.var_export($b['levels']['general_agent']['profit'] ?? null, true));

    // Member C: the live share is 80% but the settled snapshot booked 100%.
    // The report must replay the snapshot rate, so 占成金额 stays 1000 and
    // 占成盈亏 915 instead of the recomputed 800/732.
    $c = $byMember[$prefix.'_c'] ?? null;
    check($c !== null, 'Member C row missing');
    check(($c['share_amount'] ?? null) === '1000', 'C share amount must use the 100% settle-time snapshot (1000), not live 80% (800); got '.var_export($c['share_amount'] ?? null, true));
    check(($c['share_profit'] ?? null) === '915', 'C share profit must use the snapshot (915), got '.var_export($c['share_profit'] ?? null, true));

    // Aggregated summary at the director: the child level columns carry the
    // subtree's net position (−viewer income −upline residual). Branch A and
    // C keep −915 each; branch B keeps −1000 at the agent plus +85 water at
    // the 总代理. The director pays the 85 offline water the 总代理 earned.
    $summary = $data['summary'] ?? [];
    check(($summary['amount'] ?? null) === '3000', 'Summary amount must be 3000 not doubled, got '.var_export($summary['amount'] ?? null, true));
    check(($summary['levels']['agent']['share_amount'] ?? null) === '3000', 'Summary agent 占成金额 must be 3000 (100%×总投), got '.var_export($summary['levels']['agent']['share_amount'] ?? null, true));
    check(($summary['levels']['agent']['share_profit'] ?? null) === '2745', 'Summary agent 占成盈亏 must be 2745 (3×915), got '.var_export($summary['levels']['agent']['share_profit'] ?? null, true));
    check(($summary['levels']['agent']['profit'] ?? null) === '-2830', 'Summary agent 盈亏 must be -2830 (−915−1000−915), got '.var_export($summary['levels']['agent']['profit'] ?? null, true));
    check(($summary['levels']['general_agent']['amount'] ?? null) === '0', 'Summary 总代理 承接总投 must be 0 (agent holds 100%), got '.var_export($summary['levels']['general_agent']['amount'] ?? null, true));
    check(($summary['levels']['general_agent']['water'] ?? null) === '85', 'Summary 总代理 offline water must be 85 (8.5%×1000 attr), got '.var_export($summary['levels']['general_agent']['water'] ?? null, true));
    check(($summary['levels']['general_agent']['profit'] ?? null) === '85', 'Summary 总代理 盈亏 must be +85 (conservation: −viewer income), got '.var_export($summary['levels']['general_agent']['profit'] ?? null, true));
    check(!isset($summary['levels']['shareholder']), 'Summary must not contain levels absent from every chain');

    // The director is the boss: it receives no offline rebate (0), but the
    // water the subtree collected is still its cost — carried as negative
    // 赚水 and inside its total P/L.
    check(($summary['share_amount'] ?? null) === '0', 'Viewer share amount must be 0 after a 100% agent, got '.var_export($summary['share_amount'] ?? null, true));
    check(($summary['offline_water'] ?? null) === '0', 'Director offline water must be 0 (boss receives no offline rebate), got '.var_export($summary['offline_water'] ?? null, true));
    check(($summary['agent_water'] ?? null) === '-85', 'Director 总赚水 must be -85 (boss bears subtree water cost), got '.var_export($summary['agent_water'] ?? null, true));
    check(($summary['agent_profit'] ?? null) === '1745', 'Director 总盈亏 must be 1745 (915−85+915), got '.var_export($summary['agent_profit'] ?? null, true));
} finally {
    Db::rollback();
    Cache::delete('token:'.$token);
}
check(Db::name('organization_nodes')->whereIn('id', $nodeIds)->count() === 0, 'Fixture rollback failed');
echo "Report level column tests passed; fixtures rolled back\n";
