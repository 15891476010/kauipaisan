<?php
declare(strict_types=1);
namespace app\controller;

use app\service\AccountPresence;
use app\service\AgentAuthorization;
use app\service\OrganizationHierarchy;
use app\service\PasswordPolicy;
use app\service\ScoreTransfer;
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

    private function boardCode(Request $request, array $session): string
    {
        $value=strtoupper(trim((string)$request->param('board_code',$request->param('board','A'))));
        if(!preg_match('/^[A-Z][A-Z0-9_]{0,7}$/',$value))$value='A';
        $exists=Db::name('lottery_boards')->where('tenant_id',(int)($session['tenant_id']??1))->where('code',$value)->where('status',1)->value('id');
        if(!$exists) throw new \InvalidArgumentException('当前盘口不可用');
        $settings=Db::name('organization_nodes')->where('id',(int)($session['organization_id']??0))->value('settings');$decoded=is_string($settings)?json_decode($settings,true):$settings;$allowed=is_array($decoded)&&is_array($decoded['board_codes']??null)?$decoded['board_codes']:['A'];if(!in_array($value,array_map('strval',$allowed),true))throw new \InvalidArgumentException('当前层级未分配'.$value.'盘');
        return $value;
    }

    private function summary(array $session): array
    {
        $node=OrganizationHierarchy::nodeForSession($session);
        if($node)return OrganizationHierarchy::nodeCreditSummary((int)$node['id']);
        return ['total_credit'=>'0.00','allocated_credit'=>'0.00','available_credit'=>'0.00'];
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
            $result[]=['lottery_id'=>(int)$lottery['id'],'name'=>(string)$lottery['name'],'code'=>(string)$lottery['code'],'can_view'=>$row ? (int)$row['can_view']===1 : true,'can_bet'=>$row ? (int)$row['can_bet']===1 : true,'offline_rebate'=>'0.0000'];
        }
        return $result;
    }

    private function normalizePermissions(mixed $input, int $siteId, int $tenantId): array
    {
        $valid=array_map('intval',array_column($this->siteLotteries($siteId,$tenantId),'id'));
        $explicit=is_array($input); $provided=[];
        if (is_array($input)) foreach ($input as $row) if (is_array($row)) {
            $lotteryId=(int)($row['lottery_id']??0);
            if (in_array($lotteryId,$valid,true)) { $provided[$lotteryId]=['lottery_id'=>$lotteryId,'can_view'=>(bool)($row['can_view']??false),'can_bet'=>(bool)($row['can_bet']??false),'offline_rebate'=>0.0]; }
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
        foreach ($permissions as $row) $rows[]=['tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$userId,'lottery_id'=>(int)$row['lottery_id'],'can_view'=>$row['can_view']?1:0,'can_bet'=>$row['can_bet']?1:0,'offline_rebate'=>'0.0000','created_at'=>$now,'updated_at'=>$now];
        Db::name('user_lottery_permissions')->insertAll($rows);
    }

    private function memberOdds(int $siteId, int $tenantId, int $agentId, int $userId,string $boardCode='A'): array
    {
        $overrides=[];
        foreach (Db::name('user_lottery_odds')->where('site_id',$siteId)->where('agent_id',$agentId)->where('user_id',$userId)->where('board_code',$boardCode)->select()->toArray() as $row) $overrides[(int)$row['lottery_odds_id']]=$row;
        $result=[];
        foreach ($this->siteLotteries($siteId,$tenantId) as $lottery) {
            $rows=Db::name('lottery_odds')->where('lottery_id',(int)$lottery['id'])->where('board_code',$boardCode)->where('status',1)->whereNull('deleted_at')->order('sort asc')->order('id asc')->select()->toArray();
            $categories=Db::name('lottery_odds_categories')->where('lottery_id',(int)$lottery['id'])->where('board_code',$boardCode)->where('is_playable',1)->where('status',1)->whereNull('deleted_at')->order('sort asc')->order('id asc')->select()->toArray();
            foreach ($categories as $category) {
                $rows[]=['id'=>$this->directOddsId((int)$category['id']),'lottery_id'=>(int)$lottery['id'],'category_id'=>(int)$category['id'],'category'=>(string)$category['name'],'name'=>(string)$category['name'],'min_bet'=>$category['min_bet'],'odds_limit'=>$category['odds_limit'],'single_bet_limit'=>$category['single_bet_limit'],'single_item_limit'=>$category['single_item_limit'],'odds'=>$category['odds'],'offline_rebate'=>'0.0000','status'=>$category['status'],'sort'=>$category['sort'],'direct_category'=>1];
            }
            usort($rows,static fn(array $a,array $b): int => ((int)$a['sort']<=> (int)$b['sort']) ?: ((int)$a['id']<=> (int)$b['id']));
            foreach ($rows as &$row) { $override=$overrides[(int)$row['id']]??null; foreach (['min_bet','odds_limit','single_bet_limit','single_item_limit','odds'] as $field) if ($override) $row[$field]=$override[$field]; $row['offline_rebate']='0.0000'; $row['board_code']=$boardCode; $row['lottery_name']=$lottery['name']; $row['lottery_code']=$lottery['code']; }
            unset($row); $result=array_merge($result,$rows);
        }
        return $result;
    }

    private function saveOdds(int $tenantId, int $siteId, int $agentId, int $userId, mixed $input, string $now,string $boardCode='A'): void
    {
        if (!is_array($input)) return;
        $valid=Db::name('lottery_odds')->alias('o')->join('site_lotteries sl','sl.lottery_id=o.lottery_id')->where('sl.site_id',$siteId)->where('o.board_code',$boardCode)->whereNull('o.deleted_at')->field('o.id,o.lottery_id')->select()->toArray(); $map=[]; foreach ($valid as $row) $map[(int)$row['id']]=(int)$row['lottery_id'];
        $direct=Db::name('lottery_odds_categories')->alias('c')->join('site_lotteries sl','sl.lottery_id=c.lottery_id')->where('sl.site_id',$siteId)->where('c.board_code',$boardCode)->where('c.is_playable',1)->where('c.status',1)->whereNull('c.deleted_at')->field('c.id,c.lottery_id')->select()->toArray(); foreach ($direct as $row) $map[$this->directOddsId((int)$row['id'])]=(int)$row['lottery_id'];
        Db::name('user_lottery_odds')->where('site_id',$siteId)->where('agent_id',$agentId)->where('user_id',$userId)->where('board_code',$boardCode)->delete(); $rows=[];
        foreach ($input as $row) if (is_array($row) && isset($map[(int)($row['lottery_odds_id']??0)])) { $data=['tenant_id'=>$tenantId,'site_id'=>$siteId,'agent_id'=>$agentId,'user_id'=>$userId,'lottery_id'=>$map[(int)$row['lottery_odds_id']],'lottery_odds_id'=>(int)$row['lottery_odds_id'],'board_code'=>$boardCode,'created_at'=>$now,'updated_at'=>$now]; foreach(['min_bet','odds_limit','single_bet_limit','single_item_limit','odds'] as $field) { $value=$row[$field]??0; if(!is_numeric($value)||(float)$value<0) throw new \InvalidArgumentException('赔率和限额必须为非负数字'); $data[$field]=number_format((float)$value,in_array($field,['single_bet_limit','single_item_limit'],true)?2:4,'.',''); } $data['offline_rebate']='0.0000'; $rows[]=$data; }
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
        OrganizationHierarchy::applyUserScope($query,$session,'id');
        $username=trim((string)$request->param('username','')); $code=trim((string)$request->param('code','')); $status=$request->param('status','');
        if ($username !== '') $query->whereLike('username','%'.$username.'%');
        if ($code !== '') $query->whereLike('display_name','%'.$code.'%');
        if ($status !== '') $query->where('status',(int)$status);
        $page=max(1,(int)$request->param('page',1)); $pageSize=min(100,max(1,(int)$request->param('page_size',40))); $total=(clone $query)->count();
        $list=$query->field('id,username,display_name,phone,balance,credit_balance,used_balance,status,last_login_at,last_login_ip,last_login_location,created_at')->order('id desc')->page($page,$pageSize)->select()->toArray();
        AccountPresence::append($list,'site_user');
        foreach ($list as &$row) {
            $row['available_balance']=number_format(max(0,(float)$row['balance']+(float)$row['credit_balance']-(float)$row['used_balance']),2,'.','');
            $row['type']='会员';
        }
        return $this->reply(array_merge(['list'=>$list,'total'=>$total,'page'=>$page,'page_size'=>$pageSize],$this->summary($session)));
    }

    public function create(Request $request): \think\response\Json
    {
        $session=$this->session($request); $siteId=(int)$session['site_id']; $tenantId=(int)($session['tenant_id']??1); $data=$request->post();
        $node=OrganizationHierarchy::nodeForSession($session);
        if(!$node||(string)$node['level']!=='agent')throw new \InvalidArgumentException('只有代理层级可以直接创建会员');
        $nodePermissions=AgentAuthorization::sitePermissions((int)$session['site_id'],(string)$node['level']);
        if(!in_array('*',$nodePermissions,true)&&!in_array('member.create',$nodePermissions,true))throw new \InvalidArgumentException('当前未分配新增下级权限');
        $organizationId=(int)$node['id'];
        $username=trim((string)($data['username']??'')); $displayName=trim((string)($data['display_name']??$username)); $password=(string)($data['password']??''); $credit=$data['credit_balance']??0;
        if ($username==='' || !preg_match('/^[A-Za-z0-9_]{3,40}$/',$username)) throw new \InvalidArgumentException('用户名必须为3-40位字母、数字或下划线');
        if ($displayName==='') throw new \InvalidArgumentException('请输入代号');
        $password=PasswordPolicy::initial($password,$username);
        if (!is_numeric($credit) || (float)$credit<0) throw new \InvalidArgumentException('信用额度必须为非负数字');
        $summary=OrganizationHierarchy::agentCreditSummary($organizationId); if ((float)$credit>(float)$summary['available_credit']) throw new \InvalidArgumentException('代理分数不足，无法分配给用户');
        if (Db::name('site_users')->where('site_id',$siteId)->where('username',$username)->whereNull('deleted_at')->find()) throw new \InvalidArgumentException('当前站点已存在该用户名');
        $now=date('Y-m-d H:i:s'); $permissions=$this->normalizePermissions($data['permissions']??null,$siteId,$tenantId);
        $operator=['type'=>'organization_admin','id'=>(int)($session['user_id']??0),'name'=>(string)($session['username']??'')];
        $id=(int)Db::transaction(function () use ($tenantId,$siteId,$organizationId,$username,$displayName,$password,$credit,$data,$now,$permissions,$operator): int {
            $userId=(int)Db::name('site_users')->insertGetId(['tenant_id'=>$tenantId,'site_id'=>$siteId,'organization_id'=>$organizationId,'username'=>$username,'display_name'=>$displayName,'phone'=>trim((string)($data['phone']??''))?:null,'balance'=>'0.00','credit_balance'=>'0.00','used_balance'=>'0.00','password'=>password_hash($password,PASSWORD_DEFAULT),'must_change_password'=>1,'status'=>(int)($data['status']??1)===0?0:1,'created_at'=>$now,'updated_at'=>$now]);
            $user=Db::name('site_users')->where('id',$userId)->find();
            ScoreTransfer::setUserBalances($user,0.0,(float)$credit,$operator);
            $this->savePermissions($tenantId,$siteId,$userId,$permissions,$now);
            return $userId;
        });
        return $this->reply(['id'=>$id,'username'=>$username,'initial_password'=>$password,'must_change_password'=>1],'会员创建成功');
    }

    public function detail(Request $request): \think\response\Json
    {
        $session=$this->session($request); $siteId=(int)$session['site_id']; $tenantId=(int)($session['tenant_id']??1); $id=(int)$request->param('id'); $boardCode=$this->boardCode($request,$session);
        OrganizationHierarchy::assertVisibleUser($session,$id);
        $member=Db::name('site_users')->where('id',$id)->where('site_id',$siteId)->whereNull('deleted_at')->field('id,organization_id,username,display_name,remark,phone,balance,credit_balance,used_balance,interception_rate,status,account_state,last_login_at,last_login_ip,last_login_location,created_at')->find();
        if (!$member) throw new \InvalidArgumentException('会员不存在');
        $memberRows=[$member]; AccountPresence::append($memberRows,'site_user'); $member=$memberRows[0];
        $member['permissions']=$this->permissions($siteId,$tenantId,$id);
        $member['odds']=$this->memberOdds($siteId,$tenantId,(int)($session['agent_id']??0),$id,$boardCode); $member['board_code']=$boardCode;
        $member['summary']=$member['organization_id']?OrganizationHierarchy::agentCreditSummary((int)$member['organization_id']):$this->summary($session);
        return $this->reply($member);
    }

    public function update(Request $request): \think\response\Json
    {
        $session=$this->session($request); $siteId=(int)$session['site_id']; $id=(int)$request->param('id'); $current=OrganizationHierarchy::assertVisibleUser($session,$id);
        $data=$request->put(); $update=['updated_at'=>date('Y-m-d H:i:s')];
        if (array_key_exists('display_name',$data)) { $value=trim((string)$data['display_name']); if ($value==='') throw new \InvalidArgumentException('请输入代号'); $update['display_name']=$value; }
        if (array_key_exists('phone',$data)) $update['phone']=trim((string)$data['phone'])?:null;
        if (array_key_exists('credit_balance',$data)) { if (!is_numeric($data['credit_balance']) || (float)$data['credit_balance']<0) throw new \InvalidArgumentException('信用额度必须为非负数字'); $credit=(float)$data['credit_balance']; $ownerId=(int)($current['organization_id']??0); if($ownerId<1)throw new \InvalidArgumentException('历史会员尚未归属代理，请先在总平台完成归属设置'); $summary=OrganizationHierarchy::agentCreditSummary($ownerId,$id); $delta=$credit-(float)$current['credit_balance']; if($delta>(float)$summary['available_credit']+0.000001)throw new \InvalidArgumentException('所属代理可用分数不足，无法分配给用户'); $update['credit_balance']=number_format($credit,2,'.',''); }
        if (array_key_exists('status',$data)) $update['status']=(int)$data['status']===0?0:1;
        if (array_key_exists('remark',$data)) $update['remark']=mb_substr(trim((string)$data['remark']),0,255);
        if (array_key_exists('account_state',$data)) { $state=(string)$data['account_state']; if(!in_array($state,['enabled','disabled','bet_paused'],true)) throw new \InvalidArgumentException('账号状态无效'); $update['account_state']=$state; $update['status']=$state==='disabled'?0:1; }
        if (array_key_exists('interception_rate',$data)) { if(!is_numeric($data['interception_rate'])||(float)$data['interception_rate']<0||(float)$data['interception_rate']>100) throw new \InvalidArgumentException('拦货占成必须在0到100之间'); $update['interception_rate']=number_format((float)$data['interception_rate'],4,'.',''); }
        if (!empty($data['password'])) { PasswordPolicy::assertValid((string)$data['password'],(string)$current['username']); $update['password']=password_hash((string)$data['password'],PASSWORD_DEFAULT); }
        $tenantId=(int)($session['tenant_id']??1); $agentId=(int)($session['agent_id']??0); $permissions=array_key_exists('permissions',$data) ? $this->normalizePermissions($data['permissions'],$siteId,$tenantId) : null;
        $odds=$data['odds']??null; $boardCode=$this->boardCode($request,$session);
        $operator=['type'=>'organization_admin','id'=>(int)($session['user_id']??0),'name'=>(string)($session['username']??'')];
        Db::transaction(function () use ($id,$siteId,$tenantId,$agentId,$current,$update,$permissions,$odds,$operator,$boardCode): void {
            if(array_key_exists('credit_balance',$update)) {
                ScoreTransfer::setUserBalances($current,(float)$current['balance'],(float)$update['credit_balance'],$operator);
                unset($update['credit_balance']);
            }
            Db::name('site_users')->where('id',$id)->where('site_id',$siteId)->update($update);
            if ($permissions !== null) $this->savePermissions($tenantId,$siteId,$id,$permissions,(string)$update['updated_at']);
            if ($odds !== null) $this->saveOdds($tenantId,$siteId,$agentId,$id,$odds,(string)$update['updated_at'],$boardCode);
        });
        return $this->reply(null,'会员已更新');
    }
}
