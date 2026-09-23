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
    // has no 总代理/股东 level at all. share_rate is a DIRECT fraction of the
    // member turnover (60% of the book).
    $agentA = $makeNode($root, 'agent');
    $makeShare($agentA, $root, 60);
    $memberA = $makeUser($agentA, $prefix.'_a');
    $makeBet($memberA, 1000, 0); // member loses 1000

    // Branch B: member -> agent(30%) -> 总代理(20%) -> director. Direct
    // fractions: agent takes 300, 总代理 200, residual 500 reaches the root.
    $general = $makeNode($root, 'general_agent');
    $makeShare($general, $root, 20);
    $agentB = $makeNode($general, 'agent');
    $makeShare($agentB, $general, 30);
    $memberB = $makeUser($agentB, $prefix.'_b');
    $makeBet($memberB, 1000, 0);

    // Branch C: a settled record whose allocation snapshot was booked at 100%
    // share (legacy edge-rate snapshot → converts to direct 100%). The live
    // share was later cut to 80% — the report must keep the booked snapshot.
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
    $levelKeys=array_column($data['report_levels']??[],'key');
    check($levelKeys===['shareholder','director'],'Director must show only shareholder and director business columns');
    $byMember = [];
    foreach ([$agentA, $agentB, $agentC] as $target) {
        $memberRequest = (new Request())->withHeader(['authorization'=>'Bearer '.$token])->withGet(['from'=>$today, 'to'=>$today, 'organization_id'=>$target]);
        $memberRequest->setMethod('GET');
        $list = decoded((new AgentReport())->index($memberRequest))['list'] ?? [];
        foreach ($list as $memberRow) $byMember[(string)$memberRow['member']] = $memberRow['summary'];
    }

    // Member A: chain is member -> agent(60%直比) -> director(0%). The
    // agent's 占成金额 = 60%×1000=600; 占成盈亏 = 600 − 8.5%×600 = 549;
    // 赚水 = 8.5%×承接1000 = 85; 上级残余 = 40%×1000 = 400。
    $a = $byMember[$prefix.'_a'] ?? null;
    check($a !== null, 'Member A row missing');
    check($a['member_profit'] === '-1000', 'Member A profit must be -1000, got '.var_export($a['member_profit'], true));
    check($a['amount'] === '1000', 'Member A amount must count once (join fan-out), got '.var_export($a['amount'], true));
    check(($a['viewer_amount'] ?? null) === '1000', 'A: viewer 承接总投 must be 1000, got '.var_export($a['viewer_amount'] ?? null, true));
    check(($a['share_amount'] ?? null) === '600', 'A: viewer share amount must be 600 (60%×总投), got '.var_export($a['share_amount'] ?? null, true));
    check(($a['share_profit'] ?? null) === '549', 'A: viewer share profit must be 549 (600 − 51 water), got '.var_export($a['share_profit'] ?? null, true));
    check(($a['agent_profit'] ?? null) === '85', 'A: agent income must be 85 (water on its own承接), got '.var_export($a['agent_profit'] ?? null, true));
    check(($a['offline_water'] ?? null) === '85', 'A: offline water must be 85 (8.5%×own承接1000), got '.var_export($a['offline_water'] ?? null, true));
    check(($a['levels']['director']['amount'] ?? null) === '400', 'A: actual director upline residual turnover must be 400 (1000−600), got '.var_export($a['levels']['director']['amount'] ?? null, true));
    check(($a['levels']['director']['profit'] ?? null) === '366', 'A: actual director upline residual profit must be 366 (400 − 34 water), got '.var_export($a['levels']['director']['profit'] ?? null, true));
    check(!isset($a['levels']['general_agent']), 'A: skipped general-agent level must not be fabricated');
    check(!isset($a['levels']['agent']), 'A: the viewer level must not appear in level columns');
    check(!isset($a['levels']['shareholder']) && !isset($a['levels']['small_shareholder']), 'A: shareholder levels must be absent (not in chain)');

    // Member B: member -> agent(30%) -> 总代理(20%) -> director. The upline
    // residual above the agent is 70%×1000=700.
    $b = $byMember[$prefix.'_b'] ?? null;
    check($b !== null, 'Member B row missing');
    check(($b['share_amount'] ?? null) === '300', 'B: viewer share amount must be 300 (30%×总投), got '.var_export($b['share_amount'] ?? null, true));
    check(($b['share_profit'] ?? null) === '274.5', 'B: viewer share profit must be 274.5 (300 − 25.5 water), got '.var_export($b['share_profit'] ?? null, true));
    check(($b['levels']['general_agent']['amount'] ?? null) === '700', 'B: upline residual turnover must be 700, got '.var_export($b['levels']['general_agent']['amount'] ?? null, true));
    check(($b['levels']['general_agent']['profit'] ?? null) === '640.5', 'B: upline residual profit must be 640.5 (700 − 59.5 water), got '.var_export($b['levels']['general_agent']['profit'] ?? null, true));

    // Member C: the live share is 80% but the settled snapshot booked 100%
    // (legacy edge rate → converts to direct 100%). 占成金额 stays 1000 and
    // 占成盈亏 915 instead of the recomputed 800/732.
    $c = $byMember[$prefix.'_c'] ?? null;
    check($c !== null, 'Member C row missing');
    check(($c['share_amount'] ?? null) === '1000', 'C share amount must use the 100% settle-time snapshot (1000), not live 80% (800); got '.var_export($c['share_amount'] ?? null, true));
    check(($c['share_profit'] ?? null) === '915', 'C share profit must use the snapshot (915), got '.var_export($c['share_profit'] ?? null, true));

    // Aggregated summary at the director: the child level columns carry the
    // subtree's net position (−viewer income −upline residual). Branch A nets
    // −583 at the agent (shareP 549 + boss water 34 on the 400 residual);
    // branch C keeps −915; branch B keeps −915 at the agent and the 总代理
    // column shows its own book.
    $summary = $data['summary'] ?? [];
    check(($summary['amount'] ?? null) === '3000', 'Summary amount must be 3000 not doubled, got '.var_export($summary['amount'] ?? null, true));
    check(($summary['viewer_amount'] ?? null) === '960', 'Summary 承接总投 must be 960 (400+560+0) after successive residual shares, got '.var_export($summary['viewer_amount'] ?? null, true));
    check(($summary['levels']['agent']['share_amount'] ?? null) === '1900', 'Summary agent 占成金额 must be 1900 (600+300+1000), got '.var_export($summary['levels']['agent']['share_amount'] ?? null, true));
    check(($summary['levels']['agent']['share_profit'] ?? null) === '1738.5', 'Summary agent 占成盈亏 must be 1738.5 (549+274.5+915), got '.var_export($summary['levels']['agent']['share_profit'] ?? null, true));
    check(($summary['levels']['agent']['profit'] ?? null) === '-2779', 'Summary agent 盈亏 must include the top director taking every remaining share; got '.var_export($summary['levels']['agent']['profit'] ?? null, true));
    check(($summary['levels']['general_agent']['amount'] ?? null) === '700', 'Summary 总代理 承接总投 must be 700, got '.var_export($summary['levels']['general_agent']['amount'] ?? null, true));
    check(($summary['levels']['general_agent']['water'] ?? null) === '59.5', 'Summary 总代理 赚水 must be 59.5 (8.5%×own承接700), got '.var_export($summary['levels']['general_agent']['water'] ?? null, true));
    check(($summary['levels']['general_agent']['share_amount'] ?? null) === '140', 'Summary 总代理 占成金额 must be 20% of the 700 remaining after its agent, got '.var_export($summary['levels']['general_agent']['share_amount'] ?? null, true));
    check(($summary['levels']['general_agent']['share_profit'] ?? null) === '128.1', 'Summary 总代理 占成盈亏 must follow its successive 140 share, got '.var_export($summary['levels']['general_agent']['share_profit'] ?? null, true));
    check(($summary['levels']['general_agent']['profit'] ?? null) === '-688.1', 'Summary 总代理 盈亏 must conserve against the director owning the final residual, got '.var_export($summary['levels']['general_agent']['profit'] ?? null, true));
    check(!isset($summary['levels']['shareholder']), 'Summary must not contain levels absent from every chain');

    // The highest director owns every fraction left after lower levels.
    check(($summary['share_amount'] ?? null) === '960', 'Director share amount must be all remaining share (400+560+0), got '.var_export($summary['share_amount'] ?? null, true));
    check(($summary['offline_water'] ?? null) === '0', 'Director offline water must be 0, got '.var_export($summary['offline_water'] ?? null, true));
    check(($summary['agent_water'] ?? null) === '81.6', 'Director 总赚水 must be 81.6 (8.5%×own residual 400+560), got '.var_export($summary['agent_water'] ?? null, true));
    check(($summary['agent_profit'] ?? null) === '1637.1', 'Director 总盈亏 must include only lines with a positive final residual (949+688.1+0), got '.var_export($summary['agent_profit'] ?? null, true));
    check(($summary['platform_amount'] ?? null) === '0', 'No turnover may escape above the highest director, got '.var_export($summary['platform_amount'] ?? null, true));
} finally {
    Db::rollback();
    Cache::delete('token:'.$token);
}
check(Db::name('organization_nodes')->whereIn('id', $nodeIds)->count() === 0, 'Fixture rollback failed');
echo "Report level column tests passed; fixtures rolled back\n";
