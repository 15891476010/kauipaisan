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

$site = Db::name('sites')->whereNull('deleted_at')->field('id,tenant_id')->find();
if (!$site) throw new RuntimeException('A development test site is required');
$siteId = (int)$site['id'];
$tenantId = (int)$site['tenant_id'];
$prefix = 'rpttree_'.bin2hex(random_bytes(4));
$token = bin2hex(random_bytes(24));
$now = date('Y-m-d H:i:s');
$day = date('Y-m-d');
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
$makeBet = static function(int $user, string $issue, string $lottery, float $amount = 10, string $status = 'unwon') use ($siteId, $tenantId, $now): void {
    $record = (int)Db::name('bet_records')->insertGetId([
        'tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$user,'issue_no'=>$issue,'status'=>$status,
        'amount'=>$amount,'win_amount'=>0,'bet_count'=>1,'placed_at'=>$now,'created_at'=>$now,
    ]);
    Db::name('bet_details')->insert([
        'tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$user,'bet_record_id'=>$record,'issue_no'=>$issue,
        'lottery_name'=>$lottery,'number_text'=>'123','amount'=>$amount,'win_amount'=>0,'rebate'=>0,'odds'=>1,'status'=>$status,'placed_at'=>$now,
    ]);
};
$controller = new AgentReport();
$session = [];
$call = static function(array $params = [], string $method = 'index') use ($controller, $token, $day): array {
    $request = (new Request())->withHeader(['authorization'=>'Bearer '.$token])->withGet(array_merge(['from'=>$day,'to'=>$day], $params));
    $request->setMethod('GET');
    return json_decode($controller->$method($request)->getContent(), true, 512, JSON_THROW_ON_ERROR);
};
Db::startTrans();
try {
    $root = $makeNode(0, 'director', '测试总监');
    $shareholder = $makeNode($root, 'shareholder', '测试大股东');
    $small = $makeNode($shareholder, 'small_shareholder', '测试小股东');
    $general = $makeNode($small, 'general_agent', '测试总代理');
    $agent = $makeNode($general, 'agent', '测试代理');
    $directAgent = $makeNode($root, 'agent', '直属代理');
    $emptyAgent = $makeNode($root, 'agent', '无投注代理');
    $otherRoot = $makeNode(0, 'director', '另一总监');
    $otherAgent = $makeNode($otherRoot, 'agent', '另一链代理');
    $userA = $makeUser($agent, $prefix.'a');
    $userB = $makeUser($agent, $prefix.'b');
    $userC = $makeUser($directAgent, $prefix.'c');
    $outsider = $makeUser($otherAgent, $prefix.'x');
    for ($issue = 1; $issue <= 17; $issue++) {
        $makeBet($userA, $prefix.$issue, '福彩3D');
        $makeBet($userB, $prefix.$issue, '福彩3D');
    }
    $makeBet($userA, $prefix.'1', '福彩3D', 5, 'pending');
    $makeBet($userC, $prefix.'1', '福彩3D', 30);
    $makeBet($userC, $prefix.'extra', '排列三', 40);
    $makeBet($userA, $prefix.'refund', '福彩3D', 100, 'refunded');
    $makeBet($outsider, $prefix.'outside', '福彩3D', 9999);
    $session = ['scope'=>'agent','site_id'=>$siteId,'tenant_id'=>$tenantId,'organization_id'=>$root,'user_id'=>0,'username'=>$prefix];
    Cache::set('token:'.$token, $session, 300);
    $response = $call();
    check($response['code'] === 0, 'Root report failed');
    $data = $response['data'];
    check(count($data['list']) === 3, 'Root report must list three direct organizations, not all members');
    $byId = array_column($data['list'], null, 'id');
    check(isset($byId[$shareholder]), 'Shareholder branch is missing');
    check($byId[$shareholder]['member'] === '测试大股东' && $byId[$shareholder]['type'] === 'organization', 'Branch label/type is wrong');
    check($byId[$shareholder]['issue_count'] === 17, 'Two members and pending/settled bets in the same 17 issues must count as 17');
    check($byId[$shareholder]['summary']['amount'] === '345', 'Branch stake must include the full subtree exactly once');
    check($byId[$emptyAgent]['issue_count'] === 0 && $byId[$emptyAgent]['summary']['amount'] === '0', 'Empty branch must show zero');
    check($data['summary']['amount'] === '415' && $data['issue_count'] === 18, 'Root totals or distinct issue count are incorrect');
    check($data['row_label'] === '大股东 / 代理', 'Mixed direct child levels must be reflected in the name column');
    check(array_column($data['breadcrumbs'], 'id') === [$root], 'Root breadcrumb leaked another organization');

    $path = [$root];
    foreach ([$shareholder,$small,$general,$agent] as $target) {
        $path[] = $target;
        $branch = $call(['organization_id'=>$target])['data'];
        check(array_column($branch['breadcrumbs'], 'id') === $path, 'Breadcrumb path is incorrect');
        check($branch['summary']['amount'] === '345', 'Drill-down changed the subtree stake');
        check($branch['issue_count'] === 17, 'Drill-down changed distinct issue count');
        check((int)Cache::get('token:'.$token)['organization_id'] === $root, 'Browsing must not replace the authenticated root');
    }
    check($branch['row_label'] === '会员' && count($branch['list']) === 2, 'Agent report must list its members');
    check(array_unique(array_column($branch['list'], 'type')) === ['member'], 'Leaf rows must not be clickable organizations');
    $monthly = $call(['organization_id'=>$shareholder], 'monthly')['data'];
    check(count($monthly['list']) === 17 && $monthly['total']['amount'] === '345', 'Monthly report must retain selected subtree');
    $filtered = $call(['lotteries'=>'福彩3D'])['data'];
    check($filtered['summary']['amount'] === '375' && $filtered['issue_count'] === 17, 'Lottery filter must affect both totals and period count');
    $empty = $call(['from'=>'2000-01-01','to'=>'2000-01-01'])['data'];
    check($empty['summary']['amount'] === '0' && $empty['issue_count'] === 0, 'Empty date range must not reuse old values');
    foreach (['index','monthly'] as $method) {
        check($call(['organization_id'=>$otherRoot], $method)['code'] === 422, 'Cross-director report access was not rejected');
        check($call(['organization_id'=>$otherAgent], $method)['code'] === 422, 'Cross-branch access was not rejected');
        check($call(['organization_id'=>PHP_INT_MAX], $method)['code'] === 422, 'Unknown node must be rejected');
    }
    Cache::set('token:'.$token, array_merge($session, ['organization_id'=>$agent]), 300);
    check($call(['organization_id'=>$root])['code'] === 422, 'Agent must not access its ancestor');
    check(count($call()['data']['breadcrumbs']) === 1, 'Agent breadcrumb must not expose ancestors');
    Cache::set('token:'.$token, array_merge($session, ['site_id'=>$siteId+100000]), 300);
    check($call(['organization_id'=>$root])['code'] === 422, 'Cross-site request must be rejected');
    Cache::set('token:'.$token, array_merge($session, ['tenant_id'=>$tenantId+100000]), 300);
    check($call()['code'] === 422, 'Cross-tenant request must be rejected');
} finally {
    Db::rollback();
    Cache::delete('token:'.$token);
}
echo "Report drill-down tests passed; fixtures rolled back\n";
