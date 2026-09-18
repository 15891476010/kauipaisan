<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app = new think\App(dirname(__DIR__));
$app->initialize();

use app\controller\AgentSubaccount;
use app\controller\Auth;
use app\controller\Organization;
use app\middleware\ApiContext;
use app\service\AgentAuthorization;
use app\service\OrganizationHierarchy;
use think\Request;
use think\facade\Cache;
use think\facade\Db;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function payload($response): array
{
    return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
}

$site = Db::name('sites')->whereNull('deleted_at')->field('id,tenant_id,settings')->find();
if (!$site) throw new RuntimeException('A development test site is required');
$siteId = (int)$site['id'];
$tenantId = (int)$site['tenant_id'];
$prefix = 'subperm_'.bin2hex(random_bytes(4));
$ownerToken = bin2hex(random_bytes(24));
$tokens = [$ownerToken];
$now = date('Y-m-d H:i:s');
$selected = ['route.overview','route.ledger','route.reports','route.results','route.settings'];
$controller = new AgentSubaccount();
$auth = new Auth();
$middleware = new ApiContext();

Db::startTrans();
try {
    $settings = json_decode((string)$site['settings'], true) ?: [];
    $settings['agent_permissions_by_level'] = array_fill_keys(array_keys(AgentAuthorization::LEVELS), ['*']);
    Db::name('sites')->where('id', $siteId)->update(['settings'=>json_encode($settings)]);
    $orgId = (int)Db::name('organization_nodes')->insertGetId([
        'tenant_id'=>$tenantId,'site_id'=>$siteId,'parent_id'=>0,'level'=>'director',
        'name'=>$prefix,'code'=>$prefix,'path'=>'/','depth'=>1,'permissions'=>'["*"]',
        'settings'=>'{}','status'=>1,'created_at'=>$now,'updated_at'=>$now,
    ]);
    OrganizationHierarchy::rebuildPath($orgId);
    $owner = ['scope'=>'agent','tenant_id'=>$tenantId,'site_id'=>$siteId,'organization_id'=>$orgId,'user_id'=>0,'permissions'=>AgentAuthorization::codes()];
    Cache::set('token:'.$ownerToken, $owner, 300);
    $ownerRequest = (new Request())->withHeader(['authorization'=>'Bearer '.$ownerToken]);
    $options = payload($controller->options($ownerRequest))['data']['permissions'];
    $optionCodes = array_column($options, 'key');
    check(in_array('route.logs', $optionCodes, true) && in_array('route.rules', $optionCodes, true), 'Logs and rules must be selectable menus');
    check(count($optionCodes) === 9, 'Subaccounts need nine menu choices, not page/button choices');
    check(!in_array('route.subaccounts', $optionCodes, true), 'Subaccounts must not delegate account management');

    $password = bin2hex(random_bytes(12)).'Az!';
    $created = payload($controller->create((new Request())->withHeader(['authorization'=>'Bearer '.$ownerToken])->withPost([
        'username'=>$prefix,'display_name'=>$prefix,'password'=>$password,'permissions'=>$selected,'lottery_permissions'=>[],
    ])));
    check($created['code'] === 0, 'Fixture subaccount creation failed');
    $subId = (int)$created['data']['id'];
    Db::name('agent_subaccounts')->where('id', $subId)->update(['must_change_password'=>0]);
    $detail = payload($controller->detail($ownerRequest, $subId))['data'];
    check($detail['permissions'] === $selected, 'Saved menu selection must round-trip');

    $captcha = 'subperm_'.bin2hex(random_bytes(8));
    Cache::set('captcha:'.$captcha, ['answer'=>'7'], 300);
    $login = payload($auth->agentLogin((new Request())->withServer(['REMOTE_ADDR'=>'127.0.0.1'])->withPost([
        'username'=>$prefix,'password'=>$password,'captcha_id'=>$captcha,'captcha'=>'7',
    ])));
    check($login['code'] === 0, 'Fixture login failed');
    $token = $login['data']['token'];
    $tokens[] = $token;
    $permissions = $login['data']['permissions'];
    foreach (['order_details','winning_details','refunds','monthly_reports','settings.update','monthly_path'] as $code) {
        check(in_array($code, $permissions, true), 'Selected menu must include '.$code);
    }
    foreach (['route.logs','route.rules','route.subordinates','route.intercept','route.subaccounts'] as $code) {
        check(!in_array($code, $permissions, true), 'Login leaked unselected menu '.$code);
    }
    $call = static function(string $path, ?callable $next = null) use ($middleware, $token) {
        $request = (new Request())->withHeader(['authorization'=>'Bearer '.$token])->withServer(['REMOTE_ADDR'=>'127.0.0.1']);
        $request->setPathinfo('api/v1/agent/'.$path);
        $request->setMethod('GET');
        return $middleware->handle($request, $next ?? static fn()=>json(['code'=>0,'data'=>null]));
    };
    $profile = payload($call('profile', static fn($request)=>(new Organization())->profile($request)));
    check($profile['data']['permissions'] === $permissions, 'Profile refresh broadened the subaccount permissions');
    foreach (['reports','reports/monthly','order-details','winning-details','ledger/issues','settings'] as $path) {
        check($call($path)->getCode() === 200, 'Selected menu API denied: '.$path);
    }
    foreach (['organizations','members','interceptions','audit-logs','rules','subaccounts'] as $path) {
        check($call($path)->getCode() === 403, 'Unselected API permitted: '.$path);
    }

    Db::name('agent_subaccounts')->where('id', $subId)->update(['permissions'=>json_encode(['route.overview'])]);
    check($call('ledger/issues')->getCode() === 200, 'Overview must load its shared issue selector without the ledger menu');
    check($call('ledger')->getCode() === 403, 'Shared issue selector must not grant ledger data access');
    Db::name('agent_subaccounts')->where('id', $subId)->update(['permissions'=>json_encode(['logs','rules'])]);
    $legacy = payload($call('profile', static fn($request)=>(new Organization())->profile($request)))['data']['permissions'];
    check(in_array('route.logs', $legacy, true) && in_array('rules', $legacy, true), 'Legacy page selections must map to menus');
    check($call('reports')->getCode() === 403, 'An existing token retained revoked report permission');
    check($call('audit-logs')->getCode() === 200 && $call('rules')->getCode() === 200, 'Logs/rules grant not honored');

    $restore = new ReflectionMethod(Auth::class, 'sessionFromPresenceRow');
    $restored = $restore->invoke($auth, ['account_type'=>'agent_subaccount','account_id'=>$subId,'site_id'=>$siteId,'tenant_id'=>$tenantId]);
    check($restored['permissions'] === $legacy, 'Token reconstruction broadened permissions');
    $settings['agent_permissions_by_level']['director'] = ['route.rules','rules'];
    Db::name('sites')->where('id', $siteId)->update(['settings'=>json_encode($settings)]);
    check($call('audit-logs')->getCode() === 403, 'Subaccount exceeded the SaaS permission ceiling');
    check($call('rules')->getCode() === 200, 'Allowed rule menu denied');
    Db::name('agent_subaccounts')->where('id', $subId)->update(['permissions'=>'[]']);
    $empty = payload($call('profile', static fn($request)=>(new Organization())->profile($request)))['data']['permissions'];
    check($empty === [], 'Empty permissions must deny everything');
    check($call('rules')->getCode() === 403, 'Empty permissions allowed an API');
} finally {
    Db::rollback();
    foreach ($tokens as $token) Cache::delete('token:'.$token);
}
echo "Subaccount menu permissions passed; fixtures rolled back\n";
