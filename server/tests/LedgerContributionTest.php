<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app = new think\App(dirname(__DIR__));
$app->initialize();

use app\controller\AgentLedger;
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
$lottery = Db::name('site_lotteries')->alias('sl')->join('lotteries l', 'l.id=sl.lottery_id')->where('sl.site_id', (int)$site['id'])->field('l.id,l.name')->find();
if (!$lottery) throw new RuntimeException('Site has no lottery');

$prefix = 'ldgcon_'.bin2hex(random_bytes(4));
$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');
$siteId = (int)$site['id'];
$tenantId = (int)$site['tenant_id'];
$issue = 'LEDGER'.mt_rand(100, 999);
$nodeIds = [];

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
$makeUser = function(int $organizationId, string $name) use ($now, $siteId, $tenantId): int {
    return (int)Db::name('site_users')->insertGetId([
        'tenant_id'=>$tenantId, 'site_id'=>$siteId, 'organization_id'=>$organizationId,
        'username'=>$name, 'display_name'=>$name, 'password'=>password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT),
        'balance'=>'0.00', 'credit_balance'=>'0.00', 'used_balance'=>'0.00', 'status'=>1,
        'created_at'=>$now, 'updated_at'=>$now,
    ]);
};
$makeBet = function(int $userId, float $amount, float $win) use ($now, $siteId, $tenantId, $issue, $lottery): void {
    $recordId = (int)Db::name('bet_records')->insertGetId([
        'tenant_id'=>$tenantId, 'site_id'=>$siteId, 'user_id'=>$userId,
        'issue_no'=>$issue, 'status'=>$win > 0 ? 'won' : 'unwon',
        'amount'=>number_format($amount, 2, '.', ''), 'win_amount'=>number_format($win, 2, '.', ''),
        'bet_count'=>1, 'placed_at'=>$now, 'created_at'=>$now,
    ]);
    $detailId = (int)Db::name('bet_details')->insertGetId([
        'tenant_id'=>$tenantId, 'site_id'=>$siteId, 'user_id'=>$userId, 'bet_record_id'=>$recordId,
        'issue_no'=>$issue, 'number_text'=>'123', 'category'=>'直选', 'amount'=>number_format($amount, 2, '.', ''),
        'odds'=>'9.9', 'win_amount'=>number_format($win, 2, '.', ''), 'rebate'=>'0.00',
        'status'=>$win > 0 ? 'won' : 'unwon', 'placed_at'=>$now,
    ]);
    Db::name('user_stop_drops')->insert([
        'tenant_id'=>$tenantId, 'site_id'=>$siteId, 'user_id'=>$userId, 'bet_detail_id'=>$detailId,
        'lottery'=>(string)$lottery['name'], 'issue_no'=>$issue, 'number_text'=>'123', 'play_type'=>'直选',
        'stop_type'=>'none', 'original_amount'=>number_format($amount, 2, '.', ''), 'actual_amount'=>number_format($amount, 2, '.', ''),
        'stop_amount'=>'0.00', 'original_odds'=>'9.900', 'actual_odds'=>'9.900', 'drop_odds'=>'0.000',
        'source_text'=>'123直', 'placed_at'=>$now, 'created_at'=>$now, 'board_code'=>'A',
    ]);
};
$runLedger = function(int $viewerOrg) use ($issue, $lottery, $siteId, $tenantId): array {
    $token = bin2hex(random_bytes(24));
    Cache::set('token:'.$token, ['scope'=>'agent', 'site_id'=>$siteId, 'tenant_id'=>$tenantId, 'organization_id'=>$viewerOrg, 'user_id'=>0, 'username'=>'ledger-test'], 300);
    $request = (new Request())->withHeader(['authorization'=>'Bearer '.$token])->withGet(['view'=>'contribution', 'lottery'=>(string)$lottery['name'], 'from_issue'=>$issue, 'to_issue'=>$issue]);
    $request->setMethod('GET');
    $data = decoded((new AgentLedger())->index($request));
    Cache::delete('token:'.$token);
    return $data;
};

Db::startTrans();
try {
    Db::name('lottery_histories')->insert([
        'tenant_id'=>$tenantId, 'lottery_id'=>(int)$lottery['id'], 'code'=>$issue,
        'draw_day'=>$today, 'numbers'=>'123', 'is_opened'=>1,
        'created_at'=>$now, 'updated_at'=>$now,
    ]);

    $root = $makeNode(0, 'director');
    $agent = $makeNode($root, 'agent');
    Db::name('organization_profit_shares')->insert([
        'tenant_id'=>$tenantId, 'site_id'=>$siteId,
        'parent_organization_id'=>$root, 'child_organization_id'=>$agent,
        'max_share_rate'=>100, 'share_rate'=>100, 'status'=>1,
        'created_at'=>$now, 'updated_at'=>$now,
    ]);
    $member = $makeUser($agent, $prefix.'_m'); // interception_rate stays 0 on purpose
    $makeBet($member, 1000, 0); // member loses 1000

    // Viewer = the member's agent (100% share): the member contributes the
    // full book, so contribution must be 100% even though the legacy
    // site_users.interception_rate is 0.
    $agentView = $runLedger($agent);
    $agentRow = $agentView['list'][0] ?? null;
    check($agentRow !== null, 'Contribution row missing for agent view');
    check($agentRow['contribution'] === '100%', 'Agent-view contribution must be 100%, got '.var_export($agentRow['contribution'], true));
    check((float)$agentRow['actual_share_profit'] !== 0.0, 'Agent-view share profit must be nonzero');

    // Viewer = the director above a 100% agent: nothing reaches the director,
    // so this member's contribution is 0%.
    $directorView = $runLedger($root);
    $directorRow = $directorView['list'][0] ?? null;
    check($directorRow !== null, 'Contribution row missing for director view');
    check($directorRow['contribution'] === '0%', 'Director-view contribution must be 0% when the agent books 100%, got '.var_export($directorRow['contribution'], true));
} finally {
    Db::rollback();
}
check(Db::name('organization_nodes')->whereIn('id', $nodeIds)->count() === 0, 'Fixture rollback failed');
echo "Ledger contribution tests passed; fixtures rolled back\n";
