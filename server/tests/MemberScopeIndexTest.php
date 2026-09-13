<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app = new think\App(dirname(__DIR__));
$app->initialize();

use app\controller\AgentMember;
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

$prefix = 'mscope_'.bin2hex(random_bytes(4));
$token = bin2hex(random_bytes(24));
$now = date('Y-m-d H:i:s');
$siteId = (int)$site['id'];
$tenantId = (int)$site['tenant_id'];
$nodeIds = [];
$userIds = [];
$makeNode = function(int $parentId, string $level) use ($prefix, $now, $siteId, $tenantId, &$nodeIds): int {
    $id = (int)Db::name('organization_nodes')->insertGetId([
        'tenant_id'=>$tenantId, 'site_id'=>$siteId, 'parent_id'=>$parentId, 'level'=>$level,
        'name'=>$prefix.'_'.count($nodeIds), 'code'=>$prefix.'_'.count($nodeIds),
        'path'=>'/', 'depth'=>1, 'credit_limit'=>0, 'balance'=>0,
        'permissions'=>'["*"]', 'settings'=>'{"board_codes":["A"]}',
        'status'=>1, 'created_at'=>$now, 'updated_at'=>$now,
    ]);
    $nodeIds[] = $id;
    OrganizationHierarchy::rebuildPath($id);
    return $id;
};
$makeUser = function(int $organizationId, string $name, int $status = 1) use ($prefix, $now, $siteId, $tenantId, &$userIds): int {
    $id = (int)Db::name('site_users')->insertGetId([
        'tenant_id'=>$tenantId, 'site_id'=>$siteId, 'organization_id'=>$organizationId,
        'username'=>$name, 'display_name'=>$name, 'password'=>password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT),
        'balance'=>'0.00', 'credit_balance'=>'0.00', 'used_balance'=>'0.00', 'status'=>$status,
        'created_at'=>$now, 'updated_at'=>$now,
    ]);
    $userIds[] = $id;
    return $id;
};
$request = static function(array $query = []) use ($token): Request {
    $request = (new Request())->withHeader(['authorization'=>'Bearer '.$token])->withGet($query);
    $request->setMethod('GET');
    return $request;
};
$members = new AgentMember();

Db::startTrans();
try {
    $root = $makeNode(0, 'director');
    $actor = $makeNode($root, 'shareholder');
    $parent = $makeNode($actor, 'general_agent');
    $leaf = $makeNode($parent, 'agent');
    $other = $makeNode($root, 'agent');
    $memberA = $makeUser($leaf, $prefix.'_a');
    $memberB = $makeUser($leaf, $prefix.'_b', 0); // disabled member must remain listed
    $memberC = $makeUser($other, $prefix.'_c');
    $session = ['scope'=>'agent', 'site_id'=>$siteId, 'tenant_id'=>$tenantId, 'organization_id'=>$actor, 'user_id'=>0, 'username'=>'member-scope-test'];
    Cache::set('token:'.$token, $session, 300);

    // Full scope: descendants only, disabled members included, agent_name set.
    $all = decoded($members->index($request(['username'=>$prefix])));
    $ids = array_map('intval', array_column($all['list'], 'id'));
    sort($ids);
    check($ids === [$memberA, $memberB], 'Full scope must include enabled and disabled members under descendants only');
    $row = array_values(array_filter($all['list'], fn($r)=> (int)$r['id'] === $memberA))[0];
    check((int)$row['organization_id'] === $leaf, 'organization_id missing');
    check($row['agent_name'] === $prefix.'_3', 'agent_name missing or wrong: '.var_export($row['agent_name'], true));

    // Scoped to the leaf agent node: only its own members.
    $scoped = decoded($members->index($request(['organization_id'=>$leaf, 'username'=>$prefix])));
    $scopedIds = array_map('intval', array_column($scoped['list'], 'id'));
    sort($scopedIds);
    check($scopedIds === [$memberA, $memberB], 'Scoped list must return the agent members including disabled');

    // Status filter still works.
    $enabledOnly = decoded($members->index($request(['organization_id'=>$leaf, 'username'=>$prefix, 'status'=>'1'])));
    check(count($enabledOnly['list']) === 1 && (int)$enabledOnly['list'][0]['id'] === $memberA, 'Status filter failed');

    // organization_id outside the session scope must be rejected.
    try { $members->index($request(['organization_id'=>$other])); check(false, 'Out-of-scope organization_id accepted'); }
    catch (InvalidArgumentException) {}
    try { $members->index($request(['organization_id'=>$root])); check(false, 'Ancestor organization_id accepted'); }
    catch (InvalidArgumentException) {}

    // organization_id equal to the session root falls back to the full visible scope.
    $selfScope = decoded($members->index($request(['organization_id'=>$actor, 'username'=>$prefix])));
    check(count($selfScope['list']) === 2, 'Self organization scope should match the default scope');
} finally {
    Db::rollback();
    Cache::delete('token:'.$token);
}
check(Db::name('organization_nodes')->whereIn('id', $nodeIds)->count() === 0, 'Fixture rollback failed');
echo "Member scope index tests passed; fixtures rolled back\n";
