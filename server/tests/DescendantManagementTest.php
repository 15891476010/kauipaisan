<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app = new think\App(dirname(__DIR__));
$app->initialize();

use app\controller\AgentMember;
use app\controller\Organization;
use app\service\AgentAuthorization;
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
    check(($payload['code'] ?? -1) === 0, 'Controller returned an error');
    return $payload['data'] ?? [];
}

function denied(callable $operation, string $label): void
{
    try { $operation(); } catch (InvalidArgumentException $error) { return; }
    throw new RuntimeException('Scope bypass: '.$label);
}

$site = null;
foreach (Db::name('sites')->whereNull('deleted_at')->field('id,tenant_id')->select()->toArray() as $candidate) {
    $permissions = AgentAuthorization::sitePermissions((int)$candidate['id'], 'shareholder');
    if (count(array_intersect(['organization.create', 'organization.update', 'member.create', 'member.update'], $permissions)) === 4) {
        $site = $candidate;
        break;
    }
}
if (!$site) throw new RuntimeException('A test site with management permissions is required');

$prefix = 'desc_test_'.bin2hex(random_bytes(4));
$token = bin2hex(random_bytes(24));
$now = date('Y-m-d H:i:s');
$siteId = (int)$site['id'];
$tenantId = (int)$site['tenant_id'];
$nodeIds = [];
$makeNode = function(int $parentId, string $level, float $credit, float $balance) use ($prefix, $now, $siteId, $tenantId, &$nodeIds): int {
    $id = (int)Db::name('organization_nodes')->insertGetId([
        'tenant_id'=>$tenantId, 'site_id'=>$siteId, 'parent_id'=>$parentId, 'level'=>$level,
        'name'=>$prefix.'_'.count($nodeIds), 'code'=>$prefix.'_'.count($nodeIds),
        'path'=>'/', 'depth'=>1, 'credit_limit'=>$credit, 'balance'=>$balance,
        'permissions'=>'["*"]', 'settings'=>'{"board_codes":["A"]}',
        'status'=>1, 'created_at'=>$now, 'updated_at'=>$now,
    ]);
    $nodeIds[] = $id;
    OrganizationHierarchy::rebuildPath($id);
    return $id;
};
$makeAccount = function(int $nodeId) use ($prefix, $now, $siteId, $tenantId): int {
    return (int)Db::name('organization_accounts')->insertGetId([
        'tenant_id'=>$tenantId, 'site_id'=>$siteId, 'organization_id'=>$nodeId,
        'username'=>$prefix.'_'.$nodeId, 'display_name'=>'Fixture',
        'password'=>password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
        'permissions'=>'["*"]', 'status'=>1, 'created_at'=>$now, 'updated_at'=>$now,
    ]);
};
$request = static function(array $payload = [], array $query = [], string $method = 'POST') use ($token): Request {
    $request = (new Request())->withHeader(['authorization'=>'Bearer '.$token, 'content-type'=>'application/json'])
        ->withGet($query)->withPost($payload)->withInput(json_encode($payload));
    $request->setMethod($method);
    return $request;
};
$balance = static fn(int $id): float => (float)Db::name('organization_nodes')->where('id', $id)->value('balance');
$controller = new Organization();
$members = new AgentMember();

Db::startTrans();
try {
    $root = $makeNode(0, 'director', 10000, 9000);
    $actor = $makeNode($root, 'shareholder', 1000, 800);
    $parent = $makeNode($actor, 'general_agent', 200, 100);
    $leaf = $makeNode($parent, 'agent', 100, 100);
    $other = $makeNode($root, 'agent', 0, 0);
    $deleted = $makeNode($parent, 'agent', 0, 0);
    Db::name('organization_nodes')->where('id', $deleted)->update(['deleted_at'=>$now]);
    $accountId = $makeAccount($leaf);
    $otherAccountId = $makeAccount($other);
    $session = ['scope'=>'agent', 'site_id'=>$siteId, 'tenant_id'=>$tenantId, 'organization_id'=>$actor, 'user_id'=>0, 'username'=>'descendant-management-test'];
    Cache::set('token:'.$token, $session, 300);

    check((int)OrganizationHierarchy::assertManageableNode($session, $parent)['id'] === $parent, 'Direct child blocked');
    check((int)OrganizationHierarchy::assertManageableNode($session, $leaf)['id'] === $leaf, 'Grandchild blocked');
    foreach ([$actor, $root, $other, $deleted, 0] as $id) denied(fn()=>OrganizationHierarchy::assertManageableNode($session, $id), 'node '.$id);
    denied(fn()=>OrganizationHierarchy::assertManageableNode(array_merge($session, ['site_id'=>$siteId+1000000]), $leaf), 'different site');
    denied(fn()=>OrganizationHierarchy::assertManageableNode(array_merge($session, ['tenant_id'=>$tenantId+1000000]), $leaf), 'different tenant');

    $listing = decoded($controller->agentIndex($request([], ['organization_id'=>$parent], 'GET')));
    check($listing['current']['can_manage'] === true, 'Descendant list is read-only');
    check($listing['current']['permissions'] === OrganizationHierarchy::managementPermissions($session), 'Target permissions replaced operator permissions');
    check((int)$listing['breadcrumbs'][0]['id'] === $actor, 'Breadcrumb exposed ancestors');
    foreach ($listing['nodes'] as $node) check((int)$node['parent_id'] === $parent, 'List mixed unrelated branches');

    decoded($controller->agentUpdateNode($request(['display_name'=>'Updated fixture', 'name'=>'Updated fixture', 'credit_limit'=>110, 'share_rate'=>0, 'max_share_rate'=>0]), $leaf));
    check(abs($balance($parent)-90)<0.001, 'Credit must debit the actual direct parent');
    check(abs($balance($actor)-800)<0.001, 'Credit incorrectly debited the acting ancestor');
    check((int)Db::name('organization_nodes')->where('id', $leaf)->value('parent_id') === $parent, 'Update reparented the target');
    $share = Db::name('organization_profit_shares')->where('child_organization_id', $leaf)->find();
    check((int)$share['parent_organization_id'] === $parent, 'Share changed the direct parent');
    decoded($controller->agentSaveProfitShare($request(['share_rate'=>0, 'max_share_rate'=>0]), $leaf));
    decoded($controller->agentUpdateAccount($request(['display_name'=>'Changed descendant account']), $accountId));
    check(Db::name('organization_accounts')->where('id', $accountId)->value('display_name') === 'Changed descendant account', 'Grandchild account edit failed');

    foreach ([$actor, $root, $other, $deleted] as $id) {
        denied(fn()=>$controller->agentUpdateNode($request(), $id), 'update');
        denied(fn()=>$controller->agentDeleteNode($request(), $id), 'delete');
        denied(fn()=>$controller->agentSaveProfitShare($request(), $id), 'profit share');
        denied(fn()=>$controller->agentCreateAccount($request(), $id), 'account creation');
    }
    denied(fn()=>$controller->agentUpdateAccount($request(), $otherAccountId), 'account update');
    denied(fn()=>$controller->agentDeleteAccount($request(), $otherAccountId), 'account deletion');
    denied(fn()=>$controller->agentCreateNode($request(['parent_id'=>$other])), 'creation in another branch');
    denied(fn()=>$members->create($request(['organization_id'=>$other])), 'member creation in another branch');

    $created = decoded($controller->agentCreateNode($request(['parent_id'=>$parent, 'level'=>'agent', 'name'=>'New descendant', 'username'=>$prefix.'_new', 'credit_limit'=>0])));
    $createdId = (int)$created['node_id'];
    check((int)Db::name('organization_nodes')->where('id', $createdId)->value('parent_id') === $parent, 'New node attached to the operator instead of selected parent');
    $extraAccount = decoded($controller->agentCreateAccount($request(['username'=>$prefix.'_extra']), $createdId));
    decoded($controller->agentDeleteAccount($request(), (int)$extraAccount['id']));
    decoded($controller->agentDeleteNode($request(), $createdId));

    $createdMember = decoded($members->create($request(['organization_id'=>$leaf, 'username'=>$prefix.'_member', 'credit_balance'=>0])));
    $memberId = (int)$createdMember['id'];
    check((int)Db::name('site_users')->where('id', $memberId)->value('organization_id') === $leaf, 'Member assigned to wrong agent');
    $detail = decoded($members->detail($request([], ['id'=>$memberId], 'GET')));
    check((int)$detail['id'] === $memberId, 'Ancestor cannot load descendant member');
    decoded($members->update($request(['display_name'=>'Updated member', 'credit_balance'=>10], ['id'=>$memberId], 'PUT')));
    check(Db::name('site_users')->where('id', $memberId)->value('display_name') === 'Updated member', 'Ancestor member edit failed');
    check(abs($balance($leaf)-100)<0.001 && abs($balance($actor)-800)<0.001, 'Member credit used the wrong ancestor balance');
    $outsideSession = array_merge($session, ['organization_id'=>$other]);
    Cache::set('token:'.$token, $outsideSession, 300);
    denied(fn()=>$members->update($request(['display_name'=>'Forbidden'], ['id'=>$memberId], 'PUT')), 'unrelated member update');

    $restricted = array_merge($session, ['permissions'=>['route.subordinates', 'subordinates', 'organization.manage']]);
    Cache::set('token:'.$token, $restricted, 300);
    $listing = decoded($controller->agentIndex($request([], ['organization_id'=>$parent], 'GET')));
    check(!in_array('organization.update', $listing['current']['permissions'], true), 'Drill-down acquired new operation permissions');
    denied(fn()=>$controller->agentCreateNode($request(['parent_id'=>$parent])), 'restricted creation');
    denied(fn()=>$members->create($request(['organization_id'=>$leaf])), 'restricted member creation');
} finally {
    Db::rollback();
    Cache::delete('token:'.$token);
}
check(Db::name('organization_nodes')->whereIn('id', $nodeIds)->count() === 0, 'Fixture rollback failed');
echo "Descendant management tests passed; fixtures rolled back\n";
