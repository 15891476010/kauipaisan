<?php
declare(strict_types=1);
namespace app\controller;
use think\Request;
use think\facade\Db;
use think\facade\Cache;
use app\service\AuditLogger;
use app\service\AccountPresence;
use app\service\OrganizationHierarchy;
use app\service\PasswordPolicy;

final class Auth
{
    private function log(Request $request, array $context, string $action, string $resource, array $payload = []): void {
        $payload['_request'] = array_merge((array)($payload['_request'] ?? []), [
            'method' => strtoupper($request->method(true)),
            'path' => '/'.trim((string)$request->pathinfo(), '/'),
            'host' => (string)$request->host(),
            'referer' => (string)$request->header('referer'),
            'user_agent' => mb_substr((string)$request->header('user-agent'), 0, 500),
            'query' => AuditLogger::sanitize($request->get()),
            'body' => AuditLogger::sanitize($request->post()),
            'status_code' => $action === 'login_success' || $action === 'logout' ? 200 : 401,
            'success' => $action === 'login_success' || $action === 'logout',
        ]);
        AuditLogger::write($context, $action, $resource, $payload, (string)$request->ip());
    }
    private function reply(mixed $data = null, string $message = 'ok', int $code = 0): \think\response\Json { return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]); }
    private function token(int $userId, string $scope, array $context=[]): string { $token=bin2hex(random_bytes(32)); Cache::set('token:'.$token,array_merge(['user_id'=>$userId,'scope'=>$scope],$context),(int)env('TOKEN_TTL',7200)); return $token; }
    private function sessionToken(Request $request, int $userId, string $scope, array $context, string $accountType): string {
        $token=$this->token($userId,$scope,$context);
        AccountPresence::login($request,$token,array_merge(['user_id'=>$userId,'scope'=>$scope],$context),$accountType,$userId);
        return $token;
    }
    private function captchaPayload(): array {
        $left = random_int(0, 9); $right = random_int(0, 9);
        $answer = $left + $right;
        $cn = ['零','壹','贰','叁','肆','伍','陆','柒','捌','玖'];
        $leftText = random_int(0, 1) ? (string)$left : $cn[$left];
        $rightText = random_int(0, 1) ? (string)$right : $cn[$right];
        $id = bin2hex(random_bytes(16));
        Cache::set('captcha:'.$id, ['answer'=>(string)$answer], 300);
        $chars = [$leftText, ' + ', $rightText]; $x = [44, 100, 156]; $colors = ['#3f51b5','#d13b9d','#426ac2']; $text = '';
        foreach ($chars as $index => $char) { $safe = htmlspecialchars($char, ENT_XML1); $rotation = random_int(-10, 10); $text .= '<text x="'.$x[$index].'" y="58" fill="'.$colors[$index].'" transform="rotate('.$rotation.' '.$x[$index].' 48)" font-family="Arial, Microsoft YaHei, sans-serif" font-size="44" font-weight="700">'.$safe.'</text>'; }
        $lines = ''; for ($i=0; $i<5; $i++) { $y = random_int(10, 70); $lines .= '<path d="M'.random_int(0, 30).' '.$y.' C'.random_int(40, 90).' '.random_int(0, 80).','.random_int(120, 180).' '.random_int(0, 80).',220 '.random_int(0, 80).'" stroke="#'.['b7bddf','d8a7cf','aab4d4'][$i%3].'" stroke-width="1.2" fill="none" opacity=".8"/>'; }
        $dots = ''; for ($i=0; $i<24; $i++) { $dots .= '<circle cx="'.random_int(8,212).'" cy="'.random_int(8,72).'" r="'.random_int(1,2).'" fill="#8f94b2" opacity=".65"/>'; }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="220" height="80" viewBox="0 0 220 80"><rect width="220" height="80" rx="3" fill="#e8e8e8"/>'.$lines.$dots.$text.'</svg>';
        return ['captcha_id'=>$id,'image'=>'data:image/svg+xml;base64,'.base64_encode($svg),'expires_in'=>300];
    }
    public function captcha(): \think\response\Json { return $this->reply($this->captchaPayload()); }
    public function verifyCaptcha(\think\Request $request): \think\response\Json {
        $id = trim((string)$request->post('captcha_id')); $answer = trim((string)$request->post('answer')); $stored = $id !== '' ? Cache::get('captcha:'.$id) : null;
        if (!$stored) return $this->reply(null,'验证码已失效',422);
        if (!hash_equals((string)($stored['answer'] ?? ''), $answer)) return $this->reply(null,'验证码错误',422);
        return $this->reply(['verified'=>true]);
    }
    public function adminLogin(Request $request): \think\response\Json
    {
        $data=$request->post(); $username=trim((string)($data['username']??'')); $password=(string)($data['password']??'');
        $user=Db::name('admins')->where('username',$username)->where('status',1)->whereNull('deleted_at')->find();
        if ($user && password_verify($password,(string)$user['password'])) {
            $token=$this->sessionToken($request,(int)$user['id'],'admin',['admin_role'=>'platform','tenant_id'=>$user['tenant_id']??null,'site_id'=>null,'account_table'=>'admins','username'=>$user['username']],'platform_admin');
            Db::name('admins')->where('id',$user['id'])->update(['last_login_at'=>date('Y-m-d H:i:s')]);
            $this->log($request,['tenant_id'=>$user['tenant_id']??null,'user_id'=>$user['id'],'username'=>$user['username']], 'login_success', 'admin');
            return $this->reply(['token'=>$token,'expires_at'=>date(DATE_ATOM,time()+(int)env('TOKEN_TTL',7200)),'user'=>['id'=>$user['id'],'username'=>$user['username'],'display_name'=>$user['display_name'],'tenant_id'=>$user['tenant_id'],'agent_id'=>null,'site_id'=>null,'role'=>'platform'],'menus'=>$this->menuTree(true)]);
        }
        $siteAdmin=Db::name('site_admins')->where('username',$username)->where('status',1)->whereNull('deleted_at')->find();
        if (!$siteAdmin || !password_verify($password,(string)($siteAdmin['password']??''))) { $this->log($request,['username'=>$username], 'login_failed', 'admin'); return $this->reply(null,'账号或密码错误',401); }
        $agentId=(int)Db::name('sites')->where('id',(int)$siteAdmin['site_id'])->value('agent_id');
        $token=$this->sessionToken($request,(int)$siteAdmin['id'],'admin',['admin_role'=>'site','tenant_id'=>$siteAdmin['tenant_id']??null,'site_id'=>(int)$siteAdmin['site_id'],'agent_id'=>$agentId,'account_table'=>'site_admins','username'=>$siteAdmin['username']],'site_admin');
        Db::name('site_admins')->where('id',$siteAdmin['id'])->update(['last_login_at'=>date('Y-m-d H:i:s')]);
        $this->log($request,['tenant_id'=>$siteAdmin['tenant_id']??null,'agent_id'=>$agentId,'user_id'=>$siteAdmin['id'],'username'=>$siteAdmin['username']], 'login_success', 'site_admin');
        return $this->reply(['token'=>$token,'expires_at'=>date(DATE_ATOM,time()+(int)env('TOKEN_TTL',7200)),'user'=>['id'=>$siteAdmin['id'],'username'=>$siteAdmin['username'],'display_name'=>$siteAdmin['display_name'],'tenant_id'=>$siteAdmin['tenant_id'],'agent_id'=>null,'site_id'=>(int)$siteAdmin['site_id'],'role'=>'site'],'menus'=>$this->menuTree(false)]);
    }

    public function agentLogin(Request $request): \think\response\Json
    {
        $data=$request->post();
        $username=trim((string)($data['username']??''));
        $password=(string)($data['password']??'');
        $captchaId=trim((string)($data['captcha_id']??''));
        $captchaAnswer=trim((string)($data['captcha']??''));
        $captcha=$captchaId !== '' ? Cache::get('captcha:'.$captchaId) : null;
        if (!$captcha) { $this->log($request,['username'=>$username], 'login_failed', 'agent'); return $this->reply(null,'验证码已失效',422); }
        if (!hash_equals((string)($captcha['answer']??''),$captchaAnswer)) { $this->log($request,['username'=>$username], 'login_failed', 'agent'); return $this->reply(null,'验证码错误',422); }
        Cache::delete('captcha:'.$captchaId);

        $agentId=(int)($data['agent_id']??0);
        $domain=strtolower(trim((string)$request->header('x-agent-domain')));
        $siteId=0;
        if ($domain !== '') {
            $domainHost=preg_replace('/:\\d+$/','',$domain) ?: $domain;
            $domainCandidates=[$domain,$domainHost,'http://'.$domain,'https://'.$domain,'http://'.$domainHost,'https://'.$domainHost];
            $siteQuery=Db::name('domains')->whereIn('domain',$domainCandidates)->where('domain_type','agent')->where('status',1);
            $siteId=(int)$siteQuery->value('site_id');
            if ($agentId < 1) $agentId=(int)Db::name('domains')->whereIn('domain',$domainCandidates)->where('domain_type','agent')->where('status',1)->value('agent_id');
        }
        $organizationId=0; $organizationLevel='';
        $orgQuery=Db::name('organization_accounts')->where('username',$username)->where('status',1)->whereNull('deleted_at');
        if ($siteId > 0) $orgQuery->where('site_id',$siteId);
        $orgAccount=$orgQuery->find();
        $account=$orgAccount && password_verify($password,(string)$orgAccount['password']) ? $orgAccount : null;
        $accountTable=$account ? 'organization_accounts' : 'agent_admins';
        if ($account) {
            $siteId=(int)$account['site_id'];
            $agentId=(int)Db::name('sites')->where('id',$siteId)->value('agent_id');
            $organizationId=(int)$account['organization_id'];
            $organizationLevel=(string)Db::name('organization_nodes')->where('id',$organizationId)->value('level');
        } else {
            $query=Db::name('agent_admins')->where('username',$username)->where('status',1)->whereNull('deleted_at');
            if ($agentId > 0) $query->where('agent_id',$agentId);
            $account=$query->find();
        }
        if (!$account || !password_verify($password,(string)$account['password'])) {
            $subQuery=Db::name('agent_subaccounts')->where('username',$username)->where('status',1)->whereNull('deleted_at');
            if ($siteId > 0) $subQuery->where('site_id',$siteId);
            $subAccount=$subQuery->find();
            if ($subAccount && password_verify($password,(string)$subAccount['password'])) {
                $account=$subAccount;
                $accountTable='agent_subaccounts';
                $agentId=(int)$subAccount['agent_id'];
                $siteId=(int)$subAccount['site_id'];
                $organizationId=(int)($subAccount['organization_id']??0);
                if($organizationId<1){$root=OrganizationHierarchy::rootForSite($siteId);$organizationId=(int)($root['id']??0);}
                $organizationLevel=(string)Db::name('organization_nodes')->where('id',$organizationId)->value('level');
            }
        }
        if (!$account || !password_verify($password,(string)$account['password'])) {
            // Site administrators and legacy site manager credentials belong to
            // the platform/site backend only. They must not silently become a
            // root director session in the agent center.
            $this->log($request,['username'=>$username], 'login_failed', 'agent');
            return $this->reply(null,'站点管理员请从总平台站点后台登录；代理端请使用组织架构中的总监账号',401);
        }
        $platformSite=$siteId>0 && (int)Db::name('sites')->where('id',$siteId)->value('is_platform_site')===1;
        // 组织架构账号直接归属于站点和组织，不依赖旧的 agents 代理记录。
        // 只有历史代理账号仍需校验 legacy agent 的启用状态。
        // Organization accounts and their subaccounts are scoped by their
        // active organization/site records, not by the legacy `agents` table.
        // Newly-created subaccounts can legitimately carry an old/stale
        // agent_id, so checking that table here incorrectly rejected login as
        // “当前代理已停用”.
        if (!$platformSite && !in_array($accountTable,['organization_accounts','agent_subaccounts'],true) && ($agentId < 1 || !Db::name('agents')->where('id',$agentId)->where('status',1)->find())) { $this->log($request,['username'=>$username], 'login_failed', 'agent'); return $this->reply(null,'当前代理已停用',403); }

        $isSubaccount=$accountTable==='agent_subaccounts';
        $permissions=$accountTable==='organization_accounts'||$accountTable==='agent_subaccounts'?OrganizationHierarchy::effectivePermissions($organizationId,OrganizationHierarchy::decodePermissions($account['permissions']??null)):['*'];
        $lotteryPermissions=$isSubaccount?(json_decode((string)($account['lottery_permissions']??''),true)?:[]):['*'];
        if (in_array($accountTable,['organization_accounts','agent_subaccounts'],true) && ($organizationId<1 || $organizationLevel==='' || !Db::name('organization_nodes')->where('id',$organizationId)->where('site_id',$siteId)->where('status',1)->whereNull('deleted_at')->find())) { $this->log($request,['username'=>$username], 'login_failed', 'agent'); return $this->reply(null,'当前组织已停用或删除',403); }
        $accountType=match($accountTable){'site_admins'=>'site_admin','agent_subaccounts'=>'agent_subaccount','organization_accounts'=>'organization_account','sites'=>'legacy_site_admin',default=>'agent_admin'};
        $mustChangePassword=(int)($account['must_change_password']??0)===1;
        $token=$this->sessionToken($request,(int)$account['id'],'agent',['tenant_id'=>(int)$account['tenant_id'],'agent_id'=>$agentId,'site_id'=>$siteId,'organization_id'=>$organizationId?:null,'organization_level'=>$organizationLevel?:null,'account_table'=>$accountTable,'username'=>(string)$account['username'],'is_subaccount'=>$isSubaccount?1:0,'must_change_password'=>$mustChangePassword?1:0,'permissions'=>$permissions,'lottery_permissions'=>$lotteryPermissions,'report_limit_enabled'=>(int)($account['report_limit_enabled']??0),'report_from_issue'=>$account['report_from_issue']??null,'report_to_issue'=>$account['report_to_issue']??null],$accountType);
        if ($accountTable !== 'sites') Db::name($accountTable)->where('id',$account['id'])->update(['last_login_at'=>date('Y-m-d H:i:s')]);
        $this->log($request,['tenant_id'=>$account['tenant_id']??null,'agent_id'=>$agentId,'organization_id'=>$organizationId?:null,'user_id'=>$account['id'],'username'=>$account['username']], 'login_success', 'agent');
        return $this->reply(['token'=>$token,'expires_at'=>date(DATE_ATOM,time()+(int)env('TOKEN_TTL',7200)),'user'=>['id'=>$account['id'],'username'=>$account['username'],'display_name'=>$account['display_name'],'tenant_id'=>$account['tenant_id'],'agent_id'=>$agentId,'site_id'=>$siteId,'organization_id'=>$organizationId?:null,'organization_level'=>$organizationLevel?:null,'level_label'=>$organizationLevel?(OrganizationHierarchy::LABELS[$organizationLevel]??$organizationLevel):'代理','is_subaccount'=>$isSubaccount,'must_change_password'=>$mustChangePassword],'permissions'=>$permissions,'lottery_permissions'=>$lotteryPermissions]);
    }
    public function userLogin(Request $request): \think\response\Json
    {
        $data=$request->post(); $username=trim((string)($data['username']??'')); $password=(string)($data['password']??'');
        $captchaId = trim((string)($data['captcha_id'] ?? '')); $captchaAnswer = trim((string)($data['captcha'] ?? '')); $captcha = $captchaId !== '' ? Cache::get('captcha:'.$captchaId) : null;
        if (!$captcha) return $this->reply(null,'验证码已失效',422);
        if (!hash_equals((string)($captcha['answer'] ?? ''), $captchaAnswer)) return $this->reply(null,'验证码错误',422);
        Cache::delete('captcha:'.$captchaId);
        $domain=strtolower(trim((string)$request->header('x-user-domain')));
        $domainHost=preg_replace('/:\d+$/','',$domain) ?: $domain;
        $domainCandidates=$domain!=='' ? [$domain,$domainHost,'http://'.$domain,'https://'.$domain,'http://'.$domainHost,'https://'.$domainHost] : [];
        $siteIdFromDomain=$domainCandidates ? (int)Db::name('domains')->whereIn('domain',$domainCandidates)->where('domain_type','user')->where('status',1)->value('site_id') : 0;
        if ($domainCandidates && $siteIdFromDomain < 1) return $this->reply(null,'当前域名不是用户端域名',403);
        $query=Db::name('site_users')->where('username',$username)->where('status',1)->whereNull('deleted_at');
        if ($siteIdFromDomain > 0) $query->where('site_id',$siteIdFromDomain);
        if ($request->param('site_id') !== null) $query->where('site_id',(int)$request->param('site_id'));
        $user=$query->find();
        $userSiteId=(int)($user['site_id']??$siteIdFromDomain);
        $userAgentId=$userSiteId>0 ? (int)Db::name('sites')->where('id',$userSiteId)->value('agent_id') : 0;
        $userLogContext=['tenant_id'=>$user['tenant_id']??null,'agent_id'=>$userAgentId,'organization_id'=>$user['organization_id']??null,'user_id'=>$user['id']??null,'username'=>$username];
        if (!$user || !password_verify($password,(string)($user['password']??''))) { $this->log($request,$userLogContext, 'login_failed', 'user'); return $this->reply(null,'账号或密码错误',401); }
        $mustChangePassword=(int)($user['must_change_password']??0)===1;
        $context=['tenant_id'=>(int)$user['tenant_id'],'agent_id'=>$userAgentId,'site_id'=>(int)$user['site_id'],'organization_id'=>isset($user['organization_id'])?(int)$user['organization_id']:null,'user_type'=>'site-user','username'=>$user['username'],'must_change_password'=>$mustChangePassword?1:0];
        $token=$this->sessionToken($request,(int)$user['id'],'user',$context,'site_user');
        Db::name('site_users')->where('id',$user['id'])->update(['last_login_at'=>date('Y-m-d H:i:s')]);
        $this->log($request,['tenant_id'=>$user['tenant_id'],'agent_id'=>$userAgentId,'organization_id'=>$user['organization_id']??null,'user_id'=>$user['id'],'username'=>$user['username']], 'login_success', 'user');
        return $this->reply(['token'=>$token,'user'=>['id'=>$user['id'],'username'=>$user['username'],'must_change_password'=>$mustChangePassword]]);
    }
    public function logout(Request $request): \think\response\Json { $header=(string)$request->header('authorization'); $token=trim(str_ireplace('Bearer ','',$header)); $session=$token!==''?Cache::get('token:'.$token):null; if (is_array($session)) $this->log($request,$session,'logout',(string)($session['scope']??'auth')); AccountPresence::logout($token); if($token!=='') Cache::delete('token:'.$token); return $this->reply(); }
    public function heartbeat(Request $request): \think\response\Json {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token!==''?Cache::get('token:'.$token):null;
        if (!is_array($session)) return $this->reply(null,'未登录或登录已过期',401);
        AccountPresence::resume($request,$token,$session);
        AccountPresence::touch($token,$session,true);
        return $this->reply(['online'=>true,'server_time'=>date(DATE_ATOM)]);
    }
    public function userProfile(Request $request): \think\response\Json
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token !== '' ? Cache::get('token:'.$token) : null;
        if (!is_array($session) || ($session['scope'] ?? '') !== 'user') return $this->reply(null,'未登录或登录已过期',401);
        if (($session['user_type'] ?? 'site-user') === 'site-admin') return $this->reply(['balance'=>'0.00','credit_balance'=>'0.00','used_balance'=>'0.00','total_balance'=>'0.00','available_balance'=>'0.00','odds'=>[]]);

        $siteId=(int)$session['site_id'];
        $userId=(int)$session['user_id'];
        $tenantId=(int)($session['tenant_id']??1);
        $user=Db::name('site_users')->where('id',$userId)->where('site_id',$siteId)->whereNull('deleted_at')->find();
        if (!$user) return $this->reply(null,'用户不存在或已停用',404);

        $overrides=[];
        foreach (Db::name('user_lottery_odds')->where('site_id',$siteId)->where('user_id',$userId)->select()->toArray() as $override) {
            $overrides[(int)$override['lottery_odds_id']]=$override;
        }

        $lotteryFilter=trim((string)$request->param('lottery',''));
        $lotteryQuery=Db::name('lotteries')->alias('l')->join('site_lotteries sl','sl.lottery_id=l.id')
            ->where('sl.site_id',$siteId)->where('l.tenant_id',$tenantId)->where('l.status',1)->whereNull('l.deleted_at');
        if ($lotteryFilter !== '') {
            $lotteryQuery->where(function ($query) use ($lotteryFilter): void {
                $query->where('l.code',$lotteryFilter)->whereOr('l.name',$lotteryFilter);
            });
        }

        $odds=[];
        $lotteries=$lotteryQuery->field('l.id,l.name,l.code')->order('l.sort asc')->order('l.id asc')->select()->toArray();
        foreach ($lotteries as $lottery) {
            $rows=Db::name('lottery_odds')->where('lottery_id',(int)$lottery['id'])->where('status',1)->whereNull('deleted_at')->order('sort asc')->order('id asc')->select()->toArray();
            $categories=Db::name('lottery_odds_categories')->where('lottery_id',(int)$lottery['id'])->where('is_playable',1)->where('status',1)->whereNull('deleted_at')->order('sort asc')->order('id asc')->select()->toArray();
            foreach ($categories as $category) {
                $rows[]=['id'=>1000000000+(int)$category['id'],'category_id'=>(int)$category['id'],'category'=>$category['name'],'name'=>$category['name'],'min_bet'=>$category['min_bet'],'odds_limit'=>$category['odds_limit'],'single_bet_limit'=>$category['single_bet_limit'],'single_item_limit'=>$category['single_item_limit'],'odds'=>$category['odds'],'offline_rebate'=>$category['offline_rebate'],'sort'=>$category['sort'],'direct_category'=>1];
            }
            usort($rows,static fn(array $a,array $b): int => ((int)$a['sort']<=> (int)$b['sort']) ?: ((int)$a['id']<=> (int)$b['id']));
            foreach ($rows as $row) {
                $override=$overrides[(int)$row['id']]??null;
                foreach (['min_bet','odds_limit','single_bet_limit','single_item_limit','odds','offline_rebate'] as $field) {
                    if ($override && array_key_exists($field,$override)) $row[$field]=$override[$field];
                }
                $row['lottery_name']=$lottery['name'];
                $row['lottery_code']=$lottery['code'];
                $odds[]=$row;
            }
        }

        $balance=(float)($user['balance']??0);
        $credit=(float)($user['credit_balance']??0);
        $used=(float)($user['used_balance']??0);
        return $this->reply(['balance'=>number_format($balance,2,'.',''),'credit_balance'=>number_format($credit,2,'.',''),'used_balance'=>number_format($used,2,'.',''),'total_balance'=>number_format($balance+$credit,2,'.',''),'available_balance'=>number_format($balance+$credit-$used,2,'.',''),'odds'=>$odds]);
    }
    public function changeUserPassword(Request $request): \think\response\Json {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization'))); $session=$token !== '' ? Cache::get('token:'.$token) : null;
        if (!is_array($session) || ($session['scope'] ?? '') !== 'user') return $this->reply(null,'未登录或登录已过期',401);
        $user=Db::name('site_users')->where('id',(int)($session['user_id'] ?? 0))->where('site_id',(int)($session['site_id'] ?? 0))->whereNull('deleted_at')->find();
        if (!$user) return $this->reply(null,'用户不存在或已停用',404);
        $old=(string)$request->post('old_password',''); $password=(string)$request->post('password',''); $confirm=(string)$request->post('confirm_password',''); $forced=(int)($user['must_change_password']??0)===1;
        if (!$forced && !password_verify($old,(string)$user['password'])) return $this->reply(null,'原密码错误',422);
        if ($password !== $confirm) return $this->reply(null,'两次输入的新密码不一致',422);
        try { PasswordPolicy::assertValid($password,(string)$user['username'],(string)$user['password']); } catch (\InvalidArgumentException $error) { return $this->reply(null,$error->getMessage(),422); }
        Db::name('site_users')->where('id',$user['id'])->update(['password'=>password_hash($password,PASSWORD_DEFAULT),'must_change_password'=>0,'updated_at'=>date('Y-m-d H:i:s')]);
        $session['must_change_password']=0; Cache::set('token:'.$token,$session,(int)env('TOKEN_TTL',7200));
        return $this->reply(null,'密码修改成功');
    }
    public function changeAgentPassword(Request $request): \think\response\Json {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization'))); $session=$token!==''?Cache::get('token:'.$token):null;
        if(!is_array($session)||($session['scope']??'')!=='agent')return $this->reply(null,'未登录或登录已过期',401);
        $table=(string)($session['account_table']??'');
        if(!in_array($table,['organization_accounts','agent_subaccounts'],true))return $this->reply(null,'当前账号不支持此修改方式',422);
        $account=Db::name($table)->where('id',(int)$session['user_id'])->whereNull('deleted_at')->find();
        if(!$account)return $this->reply(null,'账号不存在或已停用',404);
        $old=(string)$request->post('old_password','');$password=(string)$request->post('password','');$confirm=(string)$request->post('confirm_password','');$forced=(int)($account['must_change_password']??0)===1;
        if(!$forced&&!password_verify($old,(string)$account['password']))return $this->reply(null,'原密码错误',422);
        if($password!==$confirm)return $this->reply(null,'两次输入的新密码不一致',422);
        try{PasswordPolicy::assertValid($password,(string)$account['username'],(string)$account['password']);}catch(\InvalidArgumentException $error){return $this->reply(null,$error->getMessage(),422);}
        Db::name($table)->where('id',$account['id'])->update(['password'=>password_hash($password,PASSWORD_DEFAULT),'must_change_password'=>0,'updated_at'=>date('Y-m-d H:i:s')]);
        $session['must_change_password']=0;Cache::set('token:'.$token,$session,(int)env('TOKEN_TTL',7200));
        return $this->reply(null,'密码修改成功');
    }
    public function menus(Request $request): \think\response\Json { $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization'))); $session=$token !== '' ? Cache::get('token:'.$token) : []; return $this->reply($this->menuTree(!is_array($session) || ($session['admin_role'] ?? 'platform') === 'platform')); }
    public function adminEnter(Request $request): \think\response\Json {
        $siteId=(int)$request->param('id',0);
        $site=Db::name('sites')->where('id',$siteId)->where('status',1)->whereNull('deleted_at')->find();
        $root=OrganizationHierarchy::rootForSite($siteId);
        $rootAccount=$root?Db::name('organization_accounts')->where('organization_id',(int)$root['id'])->where('status',1)->whereNull('deleted_at')->order('id asc')->find():null;
        $domain=Db::name('domains')->where('site_id',$siteId)->where('domain_type','agent')->where('status',1)->order('is_primary desc,id asc')->value('domain');
        if (!$site || !$root || !$rootAccount || !$domain) return $this->reply(null,'站点未配置可用的总监管理员或反代域名',422);
        $platformToken=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $platformSession=$platformToken!==''?Cache::get('token:'.$platformToken):[];
        $context=['tenant_id'=>(int)$site['tenant_id'],'agent_id'=>(int)$site['agent_id'],'site_id'=>$siteId,'organization_id'=>(int)$root['id'],'organization_level'=>'director','account_table'=>'organization_accounts','username'=>(string)$rootAccount['username'],'permissions'=>['*'],'impersonation'=>1,'impersonated_by'=>(int)($platformSession['user_id']??0),'impersonated_by_username'=>(string)($platformSession['username']??'平台管理员')];
        // The platform enters the site using the root organization context,
        // never by reusing a site_admins credential.
        $token=$this->sessionToken($request,(int)$rootAccount['id'],'agent',$context,'organization_impersonation');
        $domain=(string)$domain;
        $isLocal=preg_match('/^(localhost|127\\.0\\.0\\.1|\\[::1\\])(?::\\d+)?$/i',$domain) === 1;
        $url=preg_match('/^https?:\\/\\//i',$domain) ? $domain : (($isLocal?'http://':'https://').$domain);
        return $this->reply(['url'=>$url,'token'=>$token,'name'=>(string)$rootAccount['username']]);
    }
    private function menuTree(bool $platform=true): array { $rows=Db::name('menus')->where('status',1)->order('sort asc,id asc')->select()->toArray(); if (!$platform) $rows=array_values(array_filter($rows,static fn(array $row): bool => in_array($row['name'],['dashboard','site-users','bet-records'],true))); $by=[]; foreach($rows as $row){$row['children']=[];$by[$row['id']]=$row;} $tree=[]; foreach($by as $id=>&$row){if((int)$row['parent_id']===0)$tree[]=&$row;elseif(isset($by[$row['parent_id']]))$by[$row['parent_id']]['children'][]=&$row;} return $tree; }
}
