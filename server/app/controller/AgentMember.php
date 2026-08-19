<?php
declare(strict_types=1);
namespace app\controller;

use think\Request;
use think\facade\Cache;
use think\facade\Db;

final class AgentMember
{
    private function reply(mixed $data=null, string $message='ok', int $code=0): \think\response\Json
    {
        return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]);
    }

    private function session(Request $request): array
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token !== '' ? Cache::get('token:'.$token) : null;
        if (!is_array($session) || ($session['scope']??'') !== 'agent') throw new \RuntimeException('未登录或登录已过期');
        if ((int)($session['site_id']??0) < 1) throw new \RuntimeException('当前代理未绑定站点');
        return $session;
    }

    private function summary(int $siteId): array
    {
        $allocated=(float)Db::name('site_users')->where('site_id',$siteId)->whereNull('deleted_at')->sum('credit_balance');
        $settings=Db::name('sites')->where('id',$siteId)->value('settings');
        $decoded=is_string($settings) ? json_decode($settings,true) : (is_array($settings)?$settings:[]);
        $configured=(float)($decoded['credit_limit']??0);
        $total=max($configured,$allocated);
        return ['total_credit'=>number_format($total,2,'.',''),'allocated_credit'=>number_format($allocated,2,'.',''),'available_credit'=>number_format(max(0,$total-$allocated),2,'.','')];
    }

    private function siteLotteries(int $siteId, int $tenantId): array
    {
        return Db::name('lotteries')->alias('l')->join('site_lotteries sl','sl.lottery_id=l.id')
            ->where('sl.site_id',$siteId)->where('sl.tenant_id',$tenantId)->where('l.tenant_id',$tenantId)
            ->where('l.status',1)->whereNull('l.deleted_at')->field('l.id,l.name,l.code,l.sort')
            ->order('l.sort asc')->order('l.id asc')->select()->toArray();
    }

    private function permissions(int $siteId, int $tenantId, int $userId): array
    {
        $saved=[];
        foreach (Db::name('user_lottery_permissions')->where('site_id',$siteId)->where('user_id',$userId)->field('lottery_id,can_view,can_bet,offline_rebate')->select()->toArray() as $row) $saved[(int)$row['lottery_id']]=$row;
        $result=[];
        foreach ($this->siteLotteries($siteId,$tenantId) as $lottery) {
            $row=$saved[(int)$lottery['id']]??null;
            $result[]=['lottery_id'=>(int)$lottery['id'],'name'=>(string)$lottery['name'],'code'=>(string)$lottery['code'],'can_view'=>$row ? (int)$row['can_view']===1 : true,'can_bet'=>$row ? (int)$row['can_bet']===1 : true,'offline_rebate'=>number_format((float)($row['offline_rebate']??0),4,'.','')];
        }
        return $result;
    }

    private function normalizePermissions(mixed $input, int $siteId, int $tenantId): array
    {
        $valid=array_map('intval',array_column($this->siteLotteries($siteId,$tenantId),'id'));
        $explicit=is_array($input); $provided=[];
        if (is_array($input)) foreach ($input as $row) if (is_array($row)) {
            $lotteryId=(int)($row['lottery_id']??0);
            if (in_array($lotteryId,$valid,true)) { $rebate=$row['offline_rebate']??0; if (!is_numeric($rebate)||(float)$rebate<0||(float)$rebate>0.1) throw new \InvalidArgumentException('离线赚水必须在0到0.1之间'); $provided[$lotteryId]=['lottery_id'=>$lotteryId,'can_view'=>(bool)($row['can_view']??false),'can_bet'=>(bool)($row['can_bet']??false),'offline_rebate'=>(float)$rebate]; }
        }
        $result=[];
        foreach ($valid as $lotteryId) {
            $row=$provided[$lotteryId]??['lottery_id'=>$lotteryId,'can_view'=>!$explicit,'can_bet'=>!$explicit,'offline_rebate'=>0];
            if (!$row['can_view']) $row['can_bet']=false;
            $result[]=$row;
        }
        return $result;
    }

    private function savePermissions(int $tenantId, int $siteId, int $userId, array $permissions, string $now): void
    {
        Db::name('user_lottery_permissions')->where('site_id',$siteId)->where('user_id',$userId)->delete();
        if (!$permissions) return;
        $rows=[];
        foreach ($permissions as $row) $rows[]=['tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$userId,'lottery_id'=>(int)$row['lottery_id'],'can_view'=>$row['can_view']?1:0,'can_bet'=>$row['can_bet']?1:0,'offline_rebate'=>number_format((float)($row['offline_rebate']??0),4,'.',''),'created_at'=>$now,'updated_at'=>$now];
        Db::name('user_lottery_permissions')->insertAll($rows);
    }

    private function memberOdds(int $siteId, int $tenantId, int $agentId, int $userId): array
    {
        $overrides=[];
        foreach (Db::name('user_lottery_odds')->where('site_id',$siteId)->where('agent_id',$agentId)->where('user_id',$userId)->select()->toArray() as $row) $overrides[(int)$row['lottery_odds_id']]=$row;
        $result=[];
        foreach ($this->siteLotteries($siteId,$tenantId) as $lottery) {
            $rows=Db::name('lottery_odds')->where('lottery_id',(int)$lottery['id'])->where('status',1)->whereNull('deleted_at')->order('sort asc')->order('id asc')->select()->toArray();
            $categories=Db::name('lottery_odds_categories')->where('lottery_id',(int)$lottery['id'])->where('is_playable',1)->where('status',1)->whereNull('deleted_at')->order('sort asc')->order('id asc')->select()->toArray();
            foreach ($categories as $category) {
                $rows[]=['id'=>$this->directOddsId((int)$category['id']),'lottery_id'=>(int)$lottery['id'],'category_id'=>(int)$category['id'],'category'=>(string)$category['name'],'name'=>(string)$category['name'],'min_bet'=>$category['min_bet'],'odds_limit'=>$category['odds_limit'],'single_bet_limit'=>$category['single_bet_limit'],'single_item_limit'=>$category['single_item_limit'],'odds'=>$category['odds'],'offline_rebate'=>$category['offline_rebate'],'status'=>$category['status'],'sort'=>$category['sort'],'direct_category'=>1];
            }
            usort($rows,static fn(array $a,array $b): int => ((int)$a['sort']<=> (int)$b['sort']) ?: ((int)$a['id']<=> (int)$b['id']));
            foreach ($rows as &$row) { $override=$overrides[(int)$row['id']]??null; foreach (['min_bet','odds_limit','single_bet_limit','single_item_limit','odds','offline_rebate'] as $field) if ($override) $row[$field]=$override[$field]; $row['lottery_name']=$lottery['name']; $row['lottery_code']=$lottery['code']; }
            unset($row); $result=array_merge($result,$rows);
        }
        return $result;
    }

    private function saveOdds(int $tenantId, int $siteId, int $agentId, int $userId, mixed $input, string $now): void
    {
        if (!is_array($input)) return;
        $valid=Db::name('lottery_odds')->alias('o')->join('site_lotteries sl','sl.lottery_id=o.lottery_id')->where('sl.site_id',$siteId)->whereNull('o.deleted_at')->field('o.id,o.lottery_id')->select()->toArray(); $map=[]; foreach ($valid as $row) $map[(int)$row['id']]=(int)$row['lottery_id'];
        $direct=Db::name('lottery_odds_categories')->alias('c')->join('site_lotteries sl','sl.lottery_id=c.lottery_id')->where('sl.site_id',$siteId)->where('c.is_playable',1)->where('c.status',1)->whereNull('c.deleted_at')->field('c.id,c.lottery_id')->select()->toArray(); foreach ($direct as $row) $map[$this->directOddsId((int)$row['id'])]=(int)$row['lottery_id'];
        Db::name('user_lottery_odds')->where('site_id',$siteId)->where('agent_id',$agentId)->where('user_id',$userId)->delete(); $rows=[];
        foreach ($input as $row) if (is_array($row) && isset($map[(int)($row['lottery_odds_id']??0)])) { $data=['tenant_id'=>$tenantId,'site_id'=>$siteId,'agent_id'=>$agentId,'user_id'=>$userId,'lottery_id'=>$map[(int)$row['lottery_odds_id']],'lottery_odds_id'=>(int)$row['lottery_odds_id'],'created_at'=>$now,'updated_at'=>$now]; foreach(['min_bet','odds_limit','single_bet_limit','single_item_limit','odds','offline_rebate'] as $field) { $value=$row[$field]??0; if(!is_numeric($value)||(float)$value<0) throw new \InvalidArgumentException('赔率和限额必须为非负数字'); if($field==='offline_rebate'&&(float)$value>0.1) throw new \InvalidArgumentException('离线赚水必须在0到0.1之间'); $data[$field]=number_format((float)$value,in_array($field,['single_bet_limit','single_item_limit'],true)?2:4,'.',''); } $rows[]=$data; }
        if ($rows) Db::name('user_lottery_odds')->insertAll($rows);
    }

    private function directOddsId(int $categoryId): int
    {
        return 1000000000 + $categoryId;
    }

    public function index(Request $request): \think\response\Json
    {
        $session=$this->session($request); $siteId=(int)$session['site_id'];
        $query=Db::name('site_users')->where('site_id',$siteId)->whereNull('deleted_at');
        $username=trim((string)$request->param('username','')); $code=trim((string)$request->param('code','')); $status=$request->param('status','');
        if ($username !== '') $query->whereLike('username','%'.$username.'%');
        if ($code !== '') $query->whereLike('display_name','%'.$code.'%');
        if ($status !== '') $query->where('status',(int)$status);
        $page=max(1,(int)$request->param('page',1)); $pageSize=min(100,max(1,(int)$request->param('page_size',40))); $total=(clone $query)->count();
        $list=$query->field('id,username,display_name,phone,balance,credit_balance,used_balance,status,last_login_at,created_at')->order('id desc')->page($page,$pageSize)->select()->toArray();
        foreach ($list as &$row) {
            $row['available_balance']=number_format(max(0,(float)$row['balance']+(float)$row['credit_balance']-(float)$row['used_balance']),2,'.','');
            $row['type']='会员';
        }
        return $this->reply(array_merge(['list'=>$list,'total'=>$total,'page'=>$page,'page_size'=>$pageSize],$this->summary($siteId)));
    }

    public function create(Request $request): \think\response\Json
    {
        $session=$this->session($request); $siteId=(int)$session['site_id']; $tenantId=(int)($session['tenant_id']??1); $data=$request->post();
        $username=trim((string)($data['username']??'')); $displayName=trim((string)($data['display_name']??$username)); $password=(string)($data['password']??''); $credit=$data['credit_balance']??0;
        if ($username==='' || !preg_match('/^[A-Za-z0-9_]{3,40}$/',$username)) throw new \InvalidArgumentException('用户名必须为3-40位字母、数字或下划线');
        if ($displayName==='') throw new \InvalidArgumentException('请输入代号');
        if (strlen($password)<6) throw new \InvalidArgumentException('登录密码不能少于6位');
        if (!is_numeric($credit) || (float)$credit<0) throw new \InvalidArgumentException('信用额度必须为非负数字');
        if (Db::name('site_users')->where('site_id',$siteId)->where('username',$username)->whereNull('deleted_at')->find()) throw new \InvalidArgumentException('当前站点已存在该用户名');
        $now=date('Y-m-d H:i:s'); $permissions=$this->normalizePermissions($data['permissions']??null,$siteId,$tenantId);
        $id=(int)Db::transaction(function () use ($tenantId,$siteId,$username,$displayName,$password,$credit,$data,$now,$permissions): int {
            $userId=(int)Db::name('site_users')->insertGetId(['tenant_id'=>$tenantId,'site_id'=>$siteId,'username'=>$username,'display_name'=>$displayName,'phone'=>trim((string)($data['phone']??''))?:null,'balance'=>'0.00','credit_balance'=>number_format((float)$credit,2,'.',''),'used_balance'=>'0.00','password'=>password_hash($password,PASSWORD_DEFAULT),'status'=>(int)($data['status']??1)===0?0:1,'created_at'=>$now,'updated_at'=>$now]);
            $this->savePermissions($tenantId,$siteId,$userId,$permissions,$now);
            return $userId;
        });
        return $this->reply(['id'=>$id],'会员创建成功');
    }

    public function detail(Request $request): \think\response\Json
    {
        $session=$this->session($request); $siteId=(int)$session['site_id']; $tenantId=(int)($session['tenant_id']??1); $id=(int)$request->param('id');
        $member=Db::name('site_users')->where('id',$id)->where('site_id',$siteId)->whereNull('deleted_at')->field('id,username,display_name,remark,phone,balance,credit_balance,used_balance,interception_rate,status,account_state,last_login_at,created_at')->find();
        if (!$member) throw new \InvalidArgumentException('会员不存在');
        $member['permissions']=$this->permissions($siteId,$tenantId,$id);
        $member['odds']=$this->memberOdds($siteId,$tenantId,(int)($session['agent_id']??0),$id);
        $member['summary']=$this->summary($siteId);
        return $this->reply($member);
    }

    public function update(Request $request): \think\response\Json
    {
        $session=$this->session($request); $siteId=(int)$session['site_id']; $id=(int)$request->param('id'); $current=Db::name('site_users')->where('id',$id)->where('site_id',$siteId)->whereNull('deleted_at')->find();
        if (!$current) throw new \InvalidArgumentException('会员不存在');
        $data=$request->put(); $update=['updated_at'=>date('Y-m-d H:i:s')];
        if (array_key_exists('display_name',$data)) { $value=trim((string)$data['display_name']); if ($value==='') throw new \InvalidArgumentException('请输入代号'); $update['display_name']=$value; }
        if (array_key_exists('phone',$data)) $update['phone']=trim((string)$data['phone'])?:null;
        if (array_key_exists('credit_balance',$data)) { if (!is_numeric($data['credit_balance']) || (float)$data['credit_balance']<0) throw new \InvalidArgumentException('信用额度必须为非负数字'); $update['credit_balance']=number_format((float)$data['credit_balance'],2,'.',''); }
        if (array_key_exists('status',$data)) $update['status']=(int)$data['status']===0?0:1;
        if (array_key_exists('remark',$data)) $update['remark']=mb_substr(trim((string)$data['remark']),0,255);
        if (array_key_exists('account_state',$data)) { $state=(string)$data['account_state']; if(!in_array($state,['enabled','disabled','bet_paused'],true)) throw new \InvalidArgumentException('账号状态无效'); $update['account_state']=$state; $update['status']=$state==='disabled'?0:1; }
        if (array_key_exists('interception_rate',$data)) { if(!is_numeric($data['interception_rate'])||(float)$data['interception_rate']<0||(float)$data['interception_rate']>100) throw new \InvalidArgumentException('拦货占成必须在0到100之间'); $update['interception_rate']=number_format((float)$data['interception_rate'],4,'.',''); }
        if (!empty($data['password'])) { if (strlen((string)$data['password'])<6) throw new \InvalidArgumentException('登录密码不能少于6位'); $update['password']=password_hash((string)$data['password'],PASSWORD_DEFAULT); }
        $tenantId=(int)($session['tenant_id']??1); $agentId=(int)($session['agent_id']??0); $permissions=array_key_exists('permissions',$data) ? $this->normalizePermissions($data['permissions'],$siteId,$tenantId) : null;
        $odds=$data['odds']??null;
        Db::transaction(function () use ($id,$siteId,$tenantId,$agentId,$update,$permissions,$odds): void {
            Db::name('site_users')->where('id',$id)->where('site_id',$siteId)->update($update);
            if ($permissions !== null) $this->savePermissions($tenantId,$siteId,$id,$permissions,(string)$update['updated_at']);
            if ($odds !== null) $this->saveOdds($tenantId,$siteId,$agentId,$id,$odds,(string)$update['updated_at']);
        });
        return $this->reply(null,'会员已更新');
    }
}
