<?php
declare(strict_types=1);
namespace app\controller;
use think\Request;
use think\facade\Db;
use think\facade\Cache;

final class Auth
{
    private function reply(mixed $data = null, string $message = 'ok', int $code = 0): \think\response\Json { return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]); }
    private function token(int $userId, string $scope, array $context=[]): string { $token=bin2hex(random_bytes(32)); Cache::set('token:'.$token,array_merge(['user_id'=>$userId,'scope'=>$scope],$context),(int)env('TOKEN_TTL',7200)); return $token; }
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
            $token=$this->token((int)$user['id'],'admin',['admin_role'=>'platform','site_id'=>null]);
            Db::name('admins')->where('id',$user['id'])->update(['last_login_at'=>date('Y-m-d H:i:s')]);
            return $this->reply(['token'=>$token,'expires_at'=>date(DATE_ATOM,time()+(int)env('TOKEN_TTL',7200)),'user'=>['id'=>$user['id'],'username'=>$user['username'],'display_name'=>$user['display_name'],'tenant_id'=>$user['tenant_id'],'agent_id'=>null,'site_id'=>null,'role'=>'platform'],'menus'=>$this->menuTree(true)]);
        }
        $siteAdmin=Db::name('site_admins')->where('username',$username)->where('status',1)->whereNull('deleted_at')->find();
        if (!$siteAdmin || !password_verify($password,(string)$siteAdmin['password'])) return $this->reply(null,'账号或密码错误',401);
        $token=$this->token((int)$siteAdmin['id'],'admin',['admin_role'=>'site','site_id'=>(int)$siteAdmin['site_id']]);
        Db::name('site_admins')->where('id',$siteAdmin['id'])->update(['last_login_at'=>date('Y-m-d H:i:s')]);
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
        if (!$captcha) return $this->reply(null,'验证码已失效',422);
        if (!hash_equals((string)($captcha['answer']??''),$captchaAnswer)) return $this->reply(null,'验证码错误',422);
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
        $query=Db::name('agent_admins')->where('username',$username)->where('status',1)->whereNull('deleted_at');
        if ($agentId > 0) $query->where('agent_id',$agentId);
        $account=$query->find();
        $accountTable='agent_admins';
        if (!$account || !password_verify($password,(string)$account['password'])) {
            $siteQuery=Db::name('site_admins')->where('username',$username)->where('status',1)->whereNull('deleted_at');
            if ($siteId > 0) $siteQuery->where('site_id',$siteId);
            $siteAdmin=$siteQuery->find();
            if ($siteAdmin && password_verify($password,(string)$siteAdmin['password'])) {
                $account=$siteAdmin;
                $accountTable='site_admins';
                $agentId=(int)Db::name('sites')->where('id',(int)$siteAdmin['site_id'])->value('agent_id');
                $siteId=(int)$siteAdmin['site_id'];
            } else {
                $legacySiteQuery=Db::name('sites')->where('manager_username',$username)->where('status',1)->whereNull('deleted_at');
                if ($siteId > 0) $legacySiteQuery->where('id',$siteId);
                $legacySite=$legacySiteQuery->find();
                if (!$legacySite || !password_verify($password,(string)($legacySite['manager_password']??''))) return $this->reply(null,'账号或密码错误',401);
                $account=['id'=>$legacySite['id'],'username'=>$legacySite['manager_username'],'display_name'=>$legacySite['manager_username'],'tenant_id'=>$legacySite['tenant_id']];
                $accountTable='sites';
                $agentId=(int)$legacySite['agent_id'];
                $siteId=(int)$legacySite['id'];
            }
        }
        $platformSite=$siteId>0 && (int)Db::name('sites')->where('id',$siteId)->value('is_platform_site')===1;
        if (!$platformSite && ($agentId < 1 || !Db::name('agents')->where('id',$agentId)->where('status',1)->find())) return $this->reply(null,'当前代理已停用',403);

        $token=$this->token((int)$account['id'],'agent',['tenant_id'=>(int)$account['tenant_id'],'agent_id'=>$agentId,'site_id'=>$siteId,'account_table'=>$accountTable]);
        if ($accountTable !== 'sites') Db::name($accountTable)->where('id',$account['id'])->update(['last_login_at'=>date('Y-m-d H:i:s')]);
        return $this->reply(['token'=>$token,'expires_at'=>date(DATE_ATOM,time()+(int)env('TOKEN_TTL',7200)),'user'=>['id'=>$account['id'],'username'=>$account['username'],'display_name'=>$account['display_name'],'tenant_id'=>$account['tenant_id'],'agent_id'=>$agentId,'site_id'=>$siteId]]);
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
        if (!$user || !password_verify($password,(string)$user['password'])) return $this->reply(null,'账号或密码错误',401);
        return $this->reply(['token'=>$this->token((int)$user['id'],'user',['tenant_id'=>(int)$user['tenant_id'],'site_id'=>(int)$user['site_id'],'user_type'=>'site-user']),'user'=>['id'=>$user['id'],'username'=>$user['username']]]);
    }
    public function logout(Request $request): \think\response\Json { $header=(string)$request->header('authorization'); if($header) Cache::delete('token:'.trim(str_ireplace('Bearer ','',$header))); return $this->reply(); }
    public function userProfile(Request $request): \think\response\Json { $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization'))); $session=$token !== '' ? Cache::get('token:'.$token) : null; if (!is_array($session) || ($session['scope'] ?? '') !== 'user') return $this->reply(null,'未登录或登录已过期',401); if (($session['user_type'] ?? 'site-user') === 'site-admin') return $this->reply(['balance'=>'0.00','credit_balance'=>'0.00','used_balance'=>'0.00','total_balance'=>'0.00','available_balance'=>'0.00']); $user=Db::name('site_users')->where('id',(int)$session['user_id'])->where('site_id',(int)$session['site_id'])->whereNull('deleted_at')->find(); if (!$user) return $this->reply(null,'用户不存在或已停用',404); $balance=(float)($user['balance']??0); $credit=(float)($user['credit_balance']??0); $used=(float)($user['used_balance']??0); return $this->reply(['balance'=>number_format($balance,2,'.',''),'credit_balance'=>number_format($credit,2,'.',''),'used_balance'=>number_format($used,2,'.',''),'total_balance'=>number_format($balance+$credit,2,'.',''),'available_balance'=>number_format($balance+$credit-$used,2,'.','')]); }
    public function changeUserPassword(Request $request): \think\response\Json {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization'))); $session=$token !== '' ? Cache::get('token:'.$token) : null;
        if (!is_array($session) || ($session['scope'] ?? '') !== 'user') return $this->reply(null,'未登录或登录已过期',401);
        $user=Db::name('site_users')->where('id',(int)($session['user_id'] ?? 0))->where('site_id',(int)($session['site_id'] ?? 0))->whereNull('deleted_at')->find();
        if (!$user) return $this->reply(null,'用户不存在或已停用',404);
        $old=(string)$request->post('old_password',''); $password=(string)$request->post('password',''); $confirm=(string)$request->post('confirm_password','');
        if (!password_verify($old,(string)$user['password'])) return $this->reply(null,'原密码错误',422);
        if ($password !== $confirm) return $this->reply(null,'两次输入的新密码不一致',422);
        if (strlen($password) < 6 || !preg_match('/[A-Za-z]/',$password) || !preg_match('/\d/',$password)) return $this->reply(null,'新密码必须是数字和字母组合，至少6位',422);
        if ($password === (string)$user['username'] || password_verify($password,(string)$user['password'])) return $this->reply(null,'新密码不能跟账号和原密码相同',422);
        Db::name('site_users')->where('id',$user['id'])->update(['password'=>password_hash($password,PASSWORD_DEFAULT),'updated_at'=>date('Y-m-d H:i:s')]); return $this->reply(null,'密码修改成功');
    }
    public function menus(Request $request): \think\response\Json { $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization'))); $session=$token !== '' ? Cache::get('token:'.$token) : []; return $this->reply($this->menuTree(!is_array($session) || ($session['admin_role'] ?? 'platform') === 'platform')); }
    public function adminEnter(Request $request): \think\response\Json {
        $siteId=(int)$request->param('id',0);
        $site=Db::name('sites')->where('id',$siteId)->where('status',1)->whereNull('deleted_at')->find();
        $admin=Db::name('site_admins')->where('site_id',$siteId)->where('status',1)->whereNull('deleted_at')->find();
        $domain=Db::name('domains')->where('site_id',$siteId)->where('domain_type','agent')->where('status',1)->order('is_primary desc,id asc')->value('domain');
        if (!$site || (!$admin && empty($site['manager_username'])) || !$domain) return $this->reply(null,'站点未配置可用管理员或反代域名',422);
        $accountId=$admin?(int)$admin['id']:$siteId;
        $token=$this->token($accountId,'agent',['tenant_id'=>(int)$site['tenant_id'],'agent_id'=>(int)$site['agent_id'],'site_id'=>$siteId,'account_table'=>$admin?'site_admins':'sites']);
        $domain=(string)$domain;
        $isLocal=preg_match('/^(localhost|127\\.0\\.0\\.1|\\[::1\\])(?::\\d+)?$/i',$domain) === 1;
        $url=preg_match('/^https?:\\/\\//i',$domain) ? $domain : (($isLocal?'http://':'https://').$domain);
        return $this->reply(['url'=>$url,'token'=>$token,'name'=>(string)($admin['username']??$site['manager_username']??'站点管理员')]);
    }
    private function menuTree(bool $platform=true): array { $rows=Db::name('menus')->where('status',1)->order('sort asc,id asc')->select()->toArray(); if (!$platform) $rows=array_values(array_filter($rows,static fn(array $row): bool => in_array($row['name'],['dashboard','site-users','bet-records'],true))); $by=[]; foreach($rows as $row){$row['children']=[];$by[$row['id']]=$row;} $tree=[]; foreach($by as $id=>&$row){if((int)$row['parent_id']===0)$tree[]=&$row;elseif(isset($by[$row['parent_id']]))$by[$row['parent_id']]['children'][]=&$row;} return $tree; }
}
