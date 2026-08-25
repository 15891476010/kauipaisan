<?php
declare(strict_types=1);
namespace app\middleware;
use Closure;
use think\Request;
use think\Response;
use think\facade\Cache;
use think\facade\Db;
use app\service\AuditLogger;
use app\service\AccountPresence;
use app\service\AgentAuthorization;
use app\service\OrganizationHierarchy;

final class ApiContext
{
    private function cors(Response $response): Response
    {
        return $response->header([
            // This API uses bearer tokens instead of cookies, so wildcard
            // origins are safe and keep it reachable from any frontend host.
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Agent-Domain, X-User-Domain, X-Requested-With',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
            'Access-Control-Allow-Private-Network' => 'true',
            'Access-Control-Max-Age' => '86400',
        ]);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $path = trim((string)$request->pathinfo(), '/');
        $startedAt = microtime(true);
        if ($request->method(true) === 'OPTIONS') return $this->cors(response('', 204));
        if (str_contains($path, 'admin/') && !str_ends_with($path, 'admin/auth/login')) {
            $authorization = (string)$request->header('authorization');
            $token = trim(str_ireplace('Bearer ', '', $authorization));
            $session = $token !== '' ? Cache::get('token:' . $token) : null;
            if (!is_array($session) || ($session['scope'] ?? '') !== 'admin') {
                return $this->cors(json(['code'=>401,'message'=>'未登录或登录已过期','data'=>null,'request_id'=>bin2hex(random_bytes(8))], 401));
            }
        }
        if (str_starts_with($path, 'api/v1/agent/') && !str_contains($path, '/auth/') && !str_ends_with($path, '/agreement') && !str_ends_with($path, '/announcement') && !str_ends_with($path, '/lotteries')) {
            $authorization = (string)$request->header('authorization'); $token = trim(str_ireplace('Bearer ', '', $authorization)); $agentSession = $token !== '' ? Cache::get('token:'.$token) : null;
            if (!is_array($agentSession) || ($agentSession['scope']??'')!=='agent') return $this->cors(json(['code'=>401,'message'=>'未登录或登录已过期','data'=>null,'request_id'=>bin2hex(random_bytes(8))],401));
            if (in_array((string)($agentSession['account_table']??''),['site_admins','sites'],true)) {
                if($token!=='')Cache::delete('token:'.$token);
                return $this->cors(json(['code'=>401,'message'=>'站点管理员不能进入代理中心，请使用组织架构中的总监账号','data'=>null,'request_id'=>bin2hex(random_bytes(8))],401));
            }
            if (is_array($agentSession) && !empty($agentSession['is_subaccount'])) {
                $activeSubaccount=Db::name('agent_subaccounts')->where('id',(int)($agentSession['user_id']??0))->where('site_id',(int)($agentSession['site_id']??0))->where('status',1)->whereNull('deleted_at')->find();
                if(!$activeSubaccount) { if($token!=='')Cache::delete('token:'.$token); return $this->cors(json(['code'=>401,'message'=>'子账号已停用或删除','data'=>null,'request_id'=>bin2hex(random_bytes(8))],401)); }
                $permissions=OrganizationHierarchy::effectivePermissions((int)$activeSubaccount['organization_id'],OrganizationHierarchy::decodePermissions($activeSubaccount['permissions']??null));
                $lotteryPermissions=json_decode((string)($activeSubaccount['lottery_permissions']??''),true);if(!is_array($lotteryPermissions))$lotteryPermissions=[];
                $refreshed=array_merge($agentSession,['organization_id'=>(int)$activeSubaccount['organization_id'],'permissions'=>$permissions,'lottery_permissions'=>$lotteryPermissions,'report_limit_enabled'=>(int)($activeSubaccount['report_limit_enabled']??0),'report_from_issue'=>$activeSubaccount['report_from_issue']??null,'report_to_issue'=>$activeSubaccount['report_to_issue']??null]);
                if($refreshed!==$agentSession&&$token!=='')Cache::set('token:'.$token,$refreshed,(int)env('TOKEN_TTL',7200));$agentSession=$refreshed;
                $required = $this->agentPermission($path, $request);
                if ($required === '__owner__' || ($required !== '' && !$this->hasAgentPermission($permissions,$required))) return $this->cors(json(['code'=>403,'message'=>'当前子账号没有此功能权限','data'=>null,'request_id'=>bin2hex(random_bytes(8))],403));
            }
            if (($agentSession['account_table']??'')==='organization_accounts') {
                $active=Db::name('organization_accounts')->alias('a')->join('organization_nodes n','n.id=a.organization_id')->where('a.id',(int)($agentSession['user_id']??0))->where('a.status',1)->whereNull('a.deleted_at')->where('n.status',1)->whereNull('n.deleted_at')->field('a.id,a.organization_id,a.permissions,n.level AS organization_level')->find();
                if(!$active){if($token!=='')Cache::delete('token:'.$token);return $this->cors(json(['code'=>401,'message'=>'当前组织账号已停用或删除','data'=>null,'request_id'=>bin2hex(random_bytes(8))],401));}
                $permissions=OrganizationHierarchy::effectivePermissions((int)$active['organization_id'],OrganizationHierarchy::decodePermissions($active['permissions']??null));
                $refreshed=array_merge($agentSession,['organization_id'=>(int)$active['organization_id'],'organization_level'=>(string)$active['organization_level'],'permissions'=>$permissions]);
                if($refreshed!==$agentSession&&$token!=='')Cache::set('token:'.$token,$refreshed,(int)env('TOKEN_TTL',7200));$agentSession=$refreshed;
                $required=$this->agentPermission($path,$request);
                if($required!==''&&$required!=='__owner__'&&!$this->hasAgentPermission($permissions,$required))return $this->cors(json(['code'=>403,'message'=>'上级未分配此功能权限','data'=>null,'request_id'=>bin2hex(random_bytes(8))],403));
            }
            if (!in_array((string)($agentSession['account_table']??''),['organization_accounts','agent_subaccounts'],true)) {
                $legacyNode=OrganizationHierarchy::nodeForSession($agentSession);
                $organizationLevel=(string)($agentSession['organization_level']??($legacyNode['level']??'director'));
                $permissions=AgentAuthorization::sitePermissions((int)($agentSession['site_id']??0),$organizationLevel);
                $refreshed=array_merge($agentSession,['organization_level'=>$organizationLevel,'permissions'=>$permissions]);
                if($refreshed!==$agentSession&&$token!=='')Cache::set('token:'.$token,$refreshed,(int)env('TOKEN_TTL',7200));
                $required=$this->agentPermission($path,$request);
                if($required!==''&&$required!=='__owner__'&&!$this->hasAgentPermission($permissions,$required))return $this->cors(json(['code'=>403,'message'=>'SaaS 平台未向本站点开放此功能','data'=>null,'request_id'=>bin2hex(random_bytes(8))],403));
            }
        }
        $authorization = (string)$request->header('authorization');
        $token = trim(str_ireplace('Bearer ', '', $authorization));
        $activeSession = $token !== '' ? Cache::get('token:'.$token) : null;
        if (is_array($activeSession) && !empty($activeSession['must_change_password'])) {
            $allowed = str_ends_with($path, '/auth/password') || str_ends_with($path, '/auth/logout') || str_ends_with($path, '/auth/heartbeat') || str_ends_with($path, '/agreement');
            if (!$allowed && (str_starts_with($path, 'api/v1/user/') || str_starts_with($path, 'api/v1/agent/'))) {
                return $this->cors(json(['code'=>428,'message'=>'首次登录必须先修改密码','data'=>['must_change_password'=>true],'request_id'=>bin2hex(random_bytes(8))],428));
            }
        }
        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $message = env('APP_DEBUG', false) ? $e->getMessage() : '服务器内部错误';
            return $this->cors(json(['code'=>500,'message'=>$message,'data'=>null,'request_id'=>bin2hex(random_bytes(8))], 500));
        }
        $authorization = (string)$request->header('authorization');
        $token = trim(str_ireplace('Bearer ', '', $authorization));
        $session = $token !== '' ? Cache::get('token:' . $token) : null;
        if (is_array($session)) AccountPresence::touch($token,$session);
        $method = strtoupper($request->method(true));
        $isNoise = str_contains($path, 'health') || str_contains($path, '/auth/heartbeat') || str_contains($path, '/wait') || str_contains($path, 'line-options') || str_contains($path, 'captcha') || str_contains($path, 'audit-logs') || str_contains($path, 'bet-details');
        if (is_array($session) && !$isNoise && in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
            $parts = array_values(array_filter(explode('/', $path)));
            $resource = $parts[count($parts) - 1] ?? $path;
            if (ctype_digit($resource) && count($parts) > 1) $resource = $parts[count($parts) - 2];
            $action = match ($method) { 'POST' => 'create', 'PUT','PATCH' => 'update', 'DELETE' => 'delete', default => strtolower($method) };
            $responseBody = (string)$response->getContent();
            $decodedBody = json_decode($responseBody, true);
            $payload = array_merge($request->get(), $request->post(), $request->put());
            $payload['_request'] = [
                'method' => $method,
                'path' => '/'.$path,
                'host' => (string)$request->host(),
                'referer' => (string)$request->header('referer'),
                'user_agent' => mb_substr((string)$request->header('user-agent'), 0, 500),
                'query' => AuditLogger::sanitize($request->get()),
                'body' => AuditLogger::sanitize(array_merge($request->post(), $request->put())),
                'started_at' => date('Y-m-d H:i:s', (int)$startedAt),
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'status_code' => $response->getCode(),
                'success' => $response->getCode() < 400 && (!is_array($decodedBody) || (int)($decodedBody['code'] ?? 0) === 0),
                'response' => is_array($decodedBody) ? AuditLogger::sanitize($decodedBody) : mb_substr($responseBody, 0, 2000),
            ];
            $logContext=$session;
            if (empty($logContext['username']) && !empty($session['user_id'])) {
                $scope=(string)($session['scope']??''); $accountTable=(string)($session['account_table']??'agent_admins'); $table=$scope==='admin' ? (($session['admin_role']??'platform')==='site' ? 'site_admins' : 'admins') : ($scope==='agent' ? (in_array($accountTable,['site_admins','agent_subaccounts','organization_accounts'],true)?$accountTable:'agent_admins') : 'site_users');
                $logContext['username']=(string)(Db::name($table)->where('id',(int)$session['user_id'])->value('username') ?: '');
            }
            AuditLogger::write($logContext, $action, $resource, $payload, (string)$request->ip());
        }
        return $this->cors($response);
    }

    private function agentPermission(string $path, Request $request): string
    {
        $method=strtoupper($request->method(true));
        if (str_contains($path,'/subaccounts')) {
            if(str_contains($path,'/options') || ($method==='GET'))return 'subaccounts';
            if($method==='POST' && !str_contains($path,'/batch-delete'))return 'subaccount.create';
            if($method==='PUT' || $method==='PATCH')return 'subaccount.update';
            if($method==='DELETE' || str_contains($path,'/batch-delete'))return 'subaccount.delete';
            return '__owner__';
        }
        if (str_contains($path,'/organization-accounts')) return match($method){'POST'=>'organization.create','PUT','PATCH'=>'organization.update','DELETE'=>'organization.delete',default=>'organization.manage'};
        if (str_contains($path,'/profit-shares')) return in_array($method,['PUT','PATCH'],true)?'organization.update':'organization.manage';
        if (preg_match('#/organizations/\d+/accounts$#',$path)===1) return $method==='POST'?'organization.create':'organization.manage';
        if (str_contains($path,'/organizations')) return match($method){'POST'=>'organization.create','PUT','PATCH'=>'organization.update','DELETE'=>'organization.delete',default=>'organization.manage'};
        if (str_contains($path,'/ledger/issues')) return 'route.ledger';
        if (str_contains($path,'/interceptions/issues') || str_contains($path,'/interceptions/categories')) return 'route.intercept';
        if (str_contains($path,'/members')) return match($method){'POST'=>'member.create','PUT','PATCH'=>'member.update',default=>'subordinates'};
        if (str_contains($path,'/results')) return 'results';
        if (str_contains($path,'/reports/monthly')) return 'monthly_reports';
        if (str_contains($path,'/reports/issues')) return 'monthly_reports';
        if (str_contains($path,'/reports')) return 'reports';
        if (str_contains($path,'/ledger')) return match((string)$request->param('view','contribution')) { 'daily'=>'daily_ledger','monthly'=>'monthly_ledger','daily_path'=>'daily_path','monthly_path'=>'monthly_path',default=>'contribution' };
        if (str_contains($path,'/interceptions/plate')) return 'interception_plate';
        if (str_contains($path,'/interceptions')) return (string)$request->param('view','details')==='winning'?'interception_winning':'interception_details';
        if (str_contains($path,'/order-details')) return 'order_details';
        if (str_contains($path,'/winning-details')) return 'winning_details';
        if (str_contains($path,'/bet-records')) return 'bet_details';
        if (str_contains($path,'/refunds')) return 'refunds';
        if (str_contains($path,'/settings')) return in_array($method,['PUT','PATCH','POST'],true)?'settings.update':'settings';
        if (str_contains($path,'/rules')) return 'rules';
        if (str_contains($path,'/audit-logs')) return 'logs';
        return '';
    }

    private function hasAgentPermission(array $permissions,string $required): bool
    {
        if(in_array('*',$permissions,true))return true;
        if(!in_array($required,$permissions,true))return false;
        $route=match(true){
            in_array($required,['overview','order_details','winning_details','bet_details','refunds'],true)=>'route.overview',
            in_array($required,['contribution','daily_ledger','monthly_ledger','daily_path','monthly_path'],true)=>'route.ledger',
            in_array($required,['reports','monthly_reports'],true)=>'route.reports',
            $required==='results'=>'route.results',
            str_starts_with($required,'organization.')||$required==='organization.manage'=>'route.organizations',
            $required==='subordinates'||str_starts_with($required,'member.')=>'route.subordinates',
            str_starts_with($required,'interception_')=>'route.intercept',
            $required==='logs'=>'route.logs',$required==='rules'=>'route.rules',
            $required==='settings'||str_starts_with($required,'settings.')=>'route.settings',
            $required==='subaccounts'||str_starts_with($required,'subaccount.')=>'route.subaccounts',
            default=>'',
        };
        return $route===''||in_array($route,$permissions,true);
    }
}
