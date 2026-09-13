<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app = new think\App(dirname(__DIR__));
$app->initialize();

use app\controller\Organization;
use app\service\OrganizationHierarchy;
use think\Request;
use think\facade\Cache;
use think\facade\Db;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$site = Db::name('sites')->whereNull('deleted_at')->field('id,tenant_id')->find();
if (!$site) throw new RuntimeException('A test site is required');

$prefix = 'delrec_'.bin2hex(random_bytes(4));
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
$request = static function() use ($token): Request {
    $request = (new Request())->withHeader(['authorization'=>'Bearer '.$token]);
    $request->setMethod('DELETE');
    return $request;
};
$balance = static fn(int $id): float => (float)Db::name('organization_nodes')->where('id', $id)->value('balance');
$controller = new Organization();

Db::startTrans();
try {
    $actor = $makeNode($makeNode(0, 'director', 0, 0), 'shareholder', 0, 0);
    $session = ['scope'=>'agent', 'site_id'=>$siteId, 'tenant_id'=>$tenantId, 'organization_id'=>$actor, 'user_id'=>0, 'username'=>'delete-reclaim-test'];
    Cache::set('token:'.$token, $session, 300);

    // Remaining balance below the granted credit must not block deletion:
    // only the leftover score returns to the direct parent.
    $parent = $makeNode($actor, 'general_agent', 0, 1000);
    $leaf = $makeNode($parent, 'agent', 100000, 250);
    $controller->agentDeleteNode($request(), $leaf);
    check((float)Db::name('organization_nodes')->where('id', $leaf)->value('balance') === 0.0, 'Deleted node balance not zeroed');
    check(abs($balance($parent)-1250)<0.001, 'Parent must receive only the remaining balance, not the credit limit');
    check(Db::name('organization_nodes')->where('id', $leaf)->whereNotNull('deleted_at')->count() === 1, 'Node not soft-deleted');

    // Negative balance: the direct parent absorbs the debt so score stays conserved.
    $parent2 = $makeNode($actor, 'general_agent', 0, 500);
    $leaf2 = $makeNode($parent2, 'agent', 300, -120);
    $controller->agentDeleteNode($request(), $leaf2);
    check(abs($balance($parent2)-380)<0.001, 'Parent must absorb the deleted node negative balance');
    check((float)Db::name('organization_nodes')->where('id', $leaf2)->whereNotNull('deleted_at')->value('balance') === 0.0, 'Negative balance not zeroed on delete');
} finally {
    Db::rollback();
    Cache::delete('token:'.$token);
}
check(Db::name('organization_nodes')->whereIn('id', $nodeIds)->count() === 0, 'Fixture rollback failed');
echo "Delete node reclaim tests passed; fixtures rolled back\n";
