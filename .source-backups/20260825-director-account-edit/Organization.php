<?php
declare(strict_types=1);

namespace app\controller;

use app\service\AccountPresence;
use app\service\OrganizationHierarchy;
use app\service\PasswordPolicy;
use app\service\AgentAuthorization;
use app\service\ScoreTransfer;
use think\Request;
use think\facade\Cache;
use think\facade\Db;

final class Organization
{
    private function reply(mixed $data=null,string $message='ok',int $code=0): \think\response\Json
    {
        return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]);
    }

    private function site(int $siteId): array
    {
        $site=Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find();
        if (!$site) throw new \InvalidArgumentException('站点不存在');
        return $site;
    }

    private function siteMaxShareRate(array $site): float
    {
        $settings=$site['settings']??[];
        $settings=is_string($settings)?json_decode($settings,true):(is_array($settings)?$settings:[]);
        return max(0,min(100,(float)($settings['max_profit_share_rate']??100)));
    }

    private function agentSession(Request $request): array
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token!==''?Cache::get('token:'.$token):null;
        if (!is_array($session)||($session['scope']??'')!=='agent') throw new \RuntimeException('未登录或登录已过期');
        return $session;
    }
    private function requestOperator(Request $request): array
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));$session=$token!==''?Cache::get('token:'.$token):[];return['type'=>(string)(($session['scope']??'admin')==='agent'?'organization_admin':'platform_admin'),'id'=>(int)($session['user_id']??0),'name'=>(string)($session['username']??'')];
    }

    private function catalog(int $siteId,?string $level=null,array $parentPermissions=['*']): array
    {
        if($level!==null&&isset(AgentAuthorization::LEVELS[$level]))$sitePermissions=AgentAuthorization::sitePermissions($siteId,$level);
        else {
            $sitePermissions=[];
            foreach(AgentAuthorization::sitePermissionsByLevel($siteId) as $allowed){
                if(in_array('*',$allowed,true)){$sitePermissions=['*'];break;}
                $sitePermissions=array_values(array_unique(array_merge($sitePermissions,$allowed)));
            }
        }
        $sitePermissions=AgentAuthorization::intersect($sitePermissions,$parentPermissions);
        $permissions=OrganizationHierarchy::PERMISSIONS;
        if(!in_array('*',$sitePermissions,true))$permissions=array_intersect_key($permissions,array_flip($sitePermissions));
        return ['target_level'=>$level,'levels'=>array_map(static fn(string $level): array=>['value'=>$level,'label'=>OrganizationHierarchy::LABELS[$level]],OrganizationHierarchy::LEVELS),'permissions'=>array_map(static fn(string $code,string $label): array=>['code'=>$code,'label'=>$label],array_keys($permissions),array_values($permissions))];
    }

    private function generateNodeCode(int $siteId, string $level): string
    {
        $prefix=['director'=>'DIR','shareholder'=>'SH','small_shareholder'=>'SS','general_agent'=>'GA','agent'=>'AG'][$level]??'ORG';
        for($attempt=0;$attempt<10;$attempt++){
            $code=$prefix.'-'.$siteId.'-'.strtoupper(bin2hex(random_bytes(4)));
            if(!Db::name('organization_nodes')->where('site_id',$siteId)->where('code',$code)->find())return $code;
        }
        throw new \RuntimeException('组织编号生成失败，请重试');
    }

    private function nodePayload(Request $request,array $site,?array $current=null,?array $forcedParent=null): array
    {
        $parentId=$forcedParent?(int)$forcedParent['id']:(int)$request->post('parent_id',$current['parent_id']??0);
        $parent=$parentId>0?Db::name('organization_nodes')->where('id',$parentId)->where('site_id',(int)$site['id'])->whereNull('deleted_at')->find():null;
        $level=$forcedParent?(string)OrganizationHierarchy::nextLevel((string)$forcedParent['level']):(string)$request->post('level',$current['level']??'director');
        if (!in_array($level,OrganizationHierarchy::LEVELS,true)) throw new \InvalidArgumentException('组织层级无效');
        if ($level==='director'&&$parentId!==0) throw new \InvalidArgumentException('总监必须是站点根节点');
        if ($level!=='director'&&(!$parent||!OrganizationHierarchy::canParentLevelAccept((string)$parent['level'],$level))) throw new \InvalidArgumentException('上下级关系不符合总监、大股东、小股东、总代理、代理的顺序');
        // 路由/按钮权限只由 SaaS 的“站点 → 路由权限 → 层级”配置决定，
        // 组织节点和管理员账号不再允许单独覆盖。
        $permissions=AgentAuthorization::sitePermissions((int)$site['id'],$level);
        $name=trim((string)$request->post('name',$current['name']??''));
        $code=$current?(string)$current['code']:$this->generateNodeCode((int)$site['id'],$level);
        if ($name==='') throw new \InvalidArgumentException('请输入下级名称');
        $credit=(float)$request->post('credit_limit',$current['credit_limit']??0);
        if ($credit<0) throw new \InvalidArgumentException('分数额度不能小于 0');
        if($current){
            $used=(string)$level==='agent'?(float)Db::name('site_users')->where('organization_id',(int)$current['id'])->whereNull('deleted_at')->sum('credit_balance'):(float)Db::name('organization_nodes')->where('parent_id',(int)$current['id'])->whereNull('deleted_at')->sum('credit_limit');
            if($credit<$used-0.000001)throw new \InvalidArgumentException($level==='agent'?'新额度低于该代理已分配给会员的额度':'新额度低于已分配给直属下级的额度');
        }
        return ['tenant_id'=>(int)$site['tenant_id'],'site_id'=>(int)$site['id'],'parent_id'=>$parentId,'level'=>$level,'name'=>$name,'code'=>$code,'credit_limit'=>number_format($credit,2,'.',''),'permissions'=>json_encode($permissions,JSON_UNESCAPED_UNICODE),'settings'=>json_encode((array)$request->post('settings',[]),JSON_UNESCAPED_UNICODE),'status'=>(int)$request->post('status',$current['status']??1)===0?0:1,'updated_at'=>date('Y-m-d H:i:s')];
    }

    private function accountPayload(Request $request,array $node,?array $current=null): array
    {
        $username=trim((string)$request->post('username',$current['username']??''));
        $displayName=trim((string)$request->post('display_name',$current['display_name']??$username));
        if ($username==='') throw new \InvalidArgumentException('请输入登录账号');
        $duplicate=null;
        if (!$current) {
            $duplicate=Db::name('organization_accounts')->where('site_id',(int)$node['site_id'])->where('username',$username)->find();
            if ($duplicate && empty($duplicate['deleted_at'])) throw new \InvalidArgumentException('当前站点已存在账号 '.$username.'，请更换账号名');
        }
        // 管理员账号不再拥有独立的路由/按钮权限。权限唯一来源是所属组织节点，
        // 再与 SaaS 为该站点、该层级配置的权限取交集。保留数据库字段仅为兼容旧表，
        // 写入时同步组织权限，读取时由 OrganizationHierarchy 忽略账号字段。
        $permissions=AgentAuthorization::intersect(
            OrganizationHierarchy::decodePermissions($node['permissions']??null),
            AgentAuthorization::sitePermissions((int)$node['site_id'],(string)$node['level'])
        );
        $data=['tenant_id'=>(int)$node['tenant_id'],'site_id'=>(int)$node['site_id'],'organization_id'=>(int)$node['id'],'username'=>$username,'display_name'=>$displayName?:$username,'phone'=>trim((string)$request->post('phone',$current['phone']??'')),'permissions'=>json_encode($permissions,JSON_UNESCAPED_UNICODE),'status'=>(int)$request->post('status',$current['status']??1)===0?0:1,'updated_at'=>date('Y-m-d H:i:s')];
        $password=(string)$request->post('password','');
        if (!$current) {
            $password=PasswordPolicy::initial($password,$username);
            $data['password']=password_hash($password,PASSWORD_DEFAULT);
            $data['must_change_password']=1;
            $data['_initial_password']=$password;
            if ($duplicate) $data['_restore_id']=(int)$duplicate['id'];
        } elseif ($password!=='') {
            PasswordPolicy::assertValid($password,$username,(string)$current['password']);
            $data['password']=password_hash($password,PASSWORD_DEFAULT);
        }
        return $data;
    }

    private function createOrRestoreAccount(array $data): int
    {
        $restoreId=(int)($data['_restore_id']??0);
        unset($data['_restore_id']);
        if($restoreId>0){
            unset($data['created_at']);
            $data['deleted_at']=null;
            Db::name('organization_accounts')->where('id',$restoreId)->update($data);
            return $restoreId;
        }
        return (int)Db::name('organization_accounts')->insertGetId($data);
    }

    private function responseForSite(int $siteId,?int $parentId=null): array
    {
        $site=$this->site($siteId);$siteCap=$this->siteMaxShareRate($site);
        $catalogLevel=null;$catalogParentPermissions=['*'];
        if($parentId!==null){$catalogParent=Db::name('organization_nodes')->where('id',$parentId)->where('site_id',$siteId)->whereNull('deleted_at')->find();if($catalogParent){$catalogLevel=OrganizationHierarchy::nextLevel((string)$catalogParent['level'])??(string)$catalogParent['level'];$catalogParentPermissions=OrganizationHierarchy::decodePermissions($catalogParent['permissions']??null);}}
        $query=Db::name('organization_nodes')->where('site_id',$siteId)->whereNull('deleted_at');
        if ($parentId!==null) $query->where('parent_id',$parentId);
        $nodes=$query->order('path asc,id asc')->select()->toArray();
        $nodeIds=array_map('intval',array_column($nodes,'id'));
        $accounts=$nodeIds?Db::name('organization_accounts')->whereIn('organization_id',$nodeIds)->whereNull('deleted_at')->order('id asc')->select()->toArray():[];
        AccountPresence::append($accounts,'organization_account');
        $nodePermissionsById=[];
        foreach($nodes as $nodeRow)$nodePermissionsById[(int)$nodeRow['id']]=AgentAuthorization::sitePermissions((int)$siteId,(string)$nodeRow['level']);
        foreach($accounts as &$account){unset($account['password']);$account['permissions']=$nodePermissionsById[(int)$account['organization_id']]??[];}unset($account);
        $primaryAccounts=[];foreach($accounts as $account)if(!isset($primaryAccounts[(int)$account['organization_id']]))$primaryAccounts[(int)$account['organization_id']]=$account;
        $shareRows=$nodeIds?Db::name('organization_profit_shares')->whereIn('child_organization_id',$nodeIds)->where('status',1)->select()->toArray():[];
        $shares=[];foreach($shareRows as $share)$shares[(int)$share['child_organization_id']]=$share;
        foreach($nodes as &$node){
            $node['permissions']=AgentAuthorization::sitePermissions((int)$siteId,(string)$node['level']);$node['settings']=json_decode((string)($node['settings']??''),true)?:[];$node['level_label']=OrganizationHierarchy::LABELS[(string)$node['level']]??$node['level'];$node['next_level']=OrganizationHierarchy::nextLevel((string)$node['level']);$node['balance']=number_format((float)($node['balance']??0),2,'.','');
            $account=$primaryAccounts[(int)$node['id']]??[];$share=$shares[(int)$node['id']]??[];
            foreach(['id'=>'account_id','username'=>'username','display_name'=>'display_name','phone'=>'phone','online'=>'online','last_login_at'=>'last_login_at','last_login_ip'=>'last_login_ip','last_login_location'=>'last_login_location','last_login_device'=>'last_login_device']as$source=>$target)$node[$target]=$account[$source]??null;
            $node['share_rate']=number_format((float)($share['share_rate']??0),4,'.','');$node['max_share_rate']=number_format((float)($share['max_share_rate']??$siteCap),4,'.','');
            $node['child_count']=(string)$node['level']==='agent'
                ? (int)Db::name('site_users')->where('organization_id',(int)$node['id'])->whereNull('deleted_at')->count()
                : (int)Db::name('organization_nodes')->where('parent_id',(int)$node['id'])->whereNull('deleted_at')->count();
        }unset($node);
        $members=[];
        if ($parentId!==null) {
            $parent=Db::name('organization_nodes')->where('id',$parentId)->where('site_id',$siteId)->whereNull('deleted_at')->find();
            if ($parent && (string)$parent['level']==='agent') {
                $members=Db::name('site_users')->where('site_id',$siteId)->where('organization_id',$parentId)->whereNull('deleted_at')
                    ->field('id,organization_id,username,display_name,phone,balance,credit_balance,used_balance,status,last_login_at,last_login_ip,last_login_location,created_at')
                    ->order('id asc')->select()->toArray();
                AccountPresence::append($members,'site_user');
                foreach($members as &$member){
                    $member['balance']=number_format((float)($member['balance']??0),2,'.','');
                    $member['credit_balance']=number_format((float)($member['credit_balance']??0),2,'.','');
                    $member['used_balance']=number_format((float)($member['used_balance']??0),2,'.','');
                    $member['available_balance']=number_format(max(0,(float)$member['balance']+(float)$member['credit_balance']-(float)$member['used_balance']),2,'.','');
                } unset($member);
            }
        }
        return ['nodes'=>$nodes,'members'=>$members,'accounts'=>$accounts,'catalog'=>$this->catalog($siteId,$catalogLevel,$catalogParentPermissions),'site_max_share_rate'=>number_format($siteCap,4,'.','')];
    }

    private function breadcrumbs(int $siteId, int $organizationId, int $rootId): array
    {
        $chain=[]; $cursor=Db::name('organization_nodes')->where('id',$organizationId)->where('site_id',$siteId)->whereNull('deleted_at')->find();
        while($cursor){
            $chain[]=['id'=>(int)$cursor['id'],'name'=>(string)$cursor['name'],'level'=>(string)$cursor['level'],'level_label'=>OrganizationHierarchy::LABELS[(string)$cursor['level']]??(string)$cursor['level']];
            if ((int)$cursor['id']===$rootId) break;
            $parentId=(int)$cursor['parent_id'];
            $cursor=$parentId>0?Db::name('organization_nodes')->where('id',$parentId)->where('site_id',$siteId)->whereNull('deleted_at')->find():null;
        }
        return array_reverse($chain);
    }

    public function adminIndex(Request $request,int $siteId): \think\response\Json
    {
        $site=$this->site($siteId);
        $roots=Db::name('organization_nodes')->where('site_id',$siteId)->where('parent_id',0)->where('level','director')->whereNull('deleted_at')->select()->toArray();
        $siteAccount=ScoreTransfer::siteAccount((int)$site['tenant_id'],$siteId);
        $sitePayload=['id'=>(int)$site['id'],'name'=>(string)$site['name'],
            'credit_limit'=>number_format((float)$siteAccount['total_score'],2,'.',''),
            'available_balance'=>number_format((float)$siteAccount['balance'],2,'.',''),
            'director_allocated_score'=>number_format(array_sum(array_map(static fn(array $row): float=>(float)$row['credit_limit'],$roots)),2,'.',''),
            'director_count'=>count($roots)];
        return $this->reply(array_merge(['site'=>$sitePayload],$this->responseForSite($siteId)));
    }
    public function adminCreateNode(Request $request,int $siteId): \think\response\Json
    {
        $site=$this->site($siteId);$data=$this->nodePayload($request,$site);$data['created_at']=$data['updated_at'];
        if((string)$data['level']==='director'){
            $username=trim((string)$request->post('username',''));$password=(string)$request->post('password','');
            if($username==='')throw new \InvalidArgumentException('请输入总监登录账号');
            if($password==='')throw new \InvalidArgumentException('请输入总监登录密码');
        }
        $operator=$this->requestOperator($request);
        $result=Db::transaction(function()use($data,$request,$operator):array{
            $id=(int)Db::name('organization_nodes')->insertGetId($data);$node=array_merge($data,['id'=>$id,'balance'=>0]);
            ScoreTransfer::organizationAllocation($node,(float)$data['credit_limit'],$operator);
            $account=null;
            if((string)$data['level']==='director'){
                $accountData=$this->accountPayload($request,$node);$initialPassword=(string)$accountData['_initial_password'];unset($accountData['_initial_password']);
                $accountData['created_at']=$accountData['updated_at'];$accountId=$this->createOrRestoreAccount($accountData);
                $account=['id'=>$accountId,'username'=>(string)$accountData['username'],'initial_password'=>$initialPassword,'must_change_password'=>1];
            }
            return ['id'=>$id,'account'=>$account];
        });
        OrganizationHierarchy::rebuildPath((int)$result['id']);
        return $this->reply($result,'组织创建成功');
    }
    public function adminUpdateNode(Request $request,int $id): \think\response\Json { $current=Db::name('organization_nodes')->where('id',$id)->whereNull('deleted_at')->find();if(!$current)throw new \InvalidArgumentException('组织不存在');$site=$this->site((int)$current['site_id']);$data=$this->nodePayload($request,$site,$current);$operator=$this->requestOperator($request);Db::transaction(function()use($id,$current,$data,$operator):void{$delta=(float)$data['credit_limit']-(float)$current['credit_limit'];ScoreTransfer::organizationAllocation($current,$delta,$operator);Db::name('organization_nodes')->where('id',$id)->update($data);});OrganizationHierarchy::rebuildBranch($id);return $this->reply(null,'组织已更新'); }
    public function adminSetDirectorCredit(Request $request,int $id): \think\response\Json
    {
        $node=Db::name('organization_nodes')->where('id',$id)->where('parent_id',0)->where('level','director')->whereNull('deleted_at')->find();
        if(!$node) throw new \InvalidArgumentException('目标不是当前站点的根总监');
        $credit=$request->put('credit_limit',$request->post('credit_limit'));
        if(!is_numeric($credit)||(float)$credit<0) throw new \InvalidArgumentException('分数额度必须是非负数字');
        $credit=round((float)$credit,2);
        $allocated=(float)Db::name('organization_nodes')->where('parent_id',$id)->whereNull('deleted_at')->sum('credit_limit');
        if($credit+0.000001<$allocated) throw new \InvalidArgumentException('总监额度不能低于已分配给直属下级的额度');
        $operator=$this->requestOperator($request);
        $result=Db::transaction(function()use($node,$id,$credit,$allocated,$operator):array{
            $locked=Db::name('organization_nodes')->where('id',$id)->lock(true)->find();
            if(!$locked) throw new \InvalidArgumentException('总监不存在');
            $delta=round($credit-(float)$locked['credit_limit'],2);
            ScoreTransfer::organizationAllocation($locked,$delta,$operator);
            Db::name('organization_nodes')->where('id',$id)->update(['credit_limit'=>number_format($credit,2,'.',''),'updated_at'=>date('Y-m-d H:i:s')]);
            return ['id'=>$id,'credit_limit'=>number_format($credit,2,'.',''),'available_balance'=>number_format((float)$locked['balance']+$delta,2,'.',''),'direct_child_credit'=>number_format($allocated,2,'.','')];
        });
        return $this->reply($result,'总监分数已更新');
    }
    public function adminSetDirectorCreditShare(Request $request,int $id): \think\response\Json
    {
        $node=Db::name('organization_nodes')->where('id',$id)->where('parent_id',0)->where('level','director')->whereNull('deleted_at')->find();
        if(!$node)throw new \InvalidArgumentException('目标不是当前站点的根总监');
        $credit=$request->post('credit_limit');if($credit===null)$credit=$request->put('credit_limit');$max=$request->post('max_share_rate');if($max===null)$max=$request->put('max_share_rate');
        if(!is_numeric($credit)||(float)$credit<0)throw new \InvalidArgumentException('分数额度必须是非负数字');
        if(!is_numeric($max)||(float)$max<0||(float)$max>100)throw new \InvalidArgumentException('下级最高占成必须在 0 到 100 之间');
        $credit=round((float)$credit,2);$max=(float)$max;$site=$this->site((int)$node['site_id']);$siteCap=$this->siteMaxShareRate($site);
        if($max>$siteCap+0.000001)throw new \InvalidArgumentException('下级最高占成不能超过本站点上限 '.number_format($siteCap,4,'.','').'%');
        $children=Db::name('organization_nodes')->where('parent_id',$id)->whereNull('deleted_at')->select()->toArray();
        foreach($children as $child){$rate=(float)Db::name('organization_profit_shares')->where('child_organization_id',(int)$child['id'])->where('status',1)->value('share_rate');if($rate>$max+0.000001)throw new \InvalidArgumentException('下级“'.(string)$child['name'].'”当前占成高于新的最高占成，请先单独下调实际占成');}
        $allocated=(float)Db::name('organization_nodes')->where('parent_id',$id)->whereNull('deleted_at')->sum('credit_limit');if($credit+0.000001<$allocated)throw new \InvalidArgumentException('总监额度不能低于已分配给直属下级的额度');
        $operator=$this->requestOperator($request);
        $result=Db::transaction(function()use($id,$credit,$max,$children,$operator,$node):array{
            $locked=Db::name('organization_nodes')->where('id',$id)->lock(true)->find();if(!$locked)throw new \InvalidArgumentException('总监不存在');
            ScoreTransfer::organizationAllocation($locked,round($credit-(float)$locked['credit_limit'],2),$operator);
            Db::name('organization_nodes')->where('id',$id)->update(['credit_limit'=>number_format($credit,2,'.',''),'updated_at'=>date('Y-m-d H:i:s')]);$now=date('Y-m-d H:i:s');
            foreach($children as $child){$existing=Db::name('organization_profit_shares')->where('child_organization_id',(int)$child['id'])->find();$data=['tenant_id'=>(int)$node['tenant_id'],'site_id'=>(int)$node['site_id'],'parent_organization_id'=>$id,'child_organization_id'=>(int)$child['id'],'max_share_rate'=>number_format($max,4,'.',''),'share_rate'=>number_format((float)($existing['share_rate']??0),4,'.',''),'status'=>1,'updated_at'=>$now];if($existing)Db::name('organization_profit_shares')->where('id',(int)$existing['id'])->update($data);else{$data['created_at']=$now;Db::name('organization_profit_shares')->insert($data);}}
            return ['id'=>$id,'credit_limit'=>number_format($credit,2,'.',''),'max_share_rate'=>number_format($max,4,'.',''),'child_count'=>count($children)];
        });
        return $this->reply($result,'总监分数和下级最高占成已更新');
    }
    public function adminDeleteNode(Request $request,int $id): \think\response\Json { $node=Db::name('organization_nodes')->where('id',$id)->whereNull('deleted_at')->find();if(!$node)throw new \InvalidArgumentException('组织不存在');if(Db::name('organization_nodes')->where('parent_id',$id)->whereNull('deleted_at')->count()>0)throw new \InvalidArgumentException('请先处理该组织的下级');if(Db::name('site_users')->where('organization_id',$id)->whereNull('deleted_at')->count()>0)throw new \InvalidArgumentException('请先转移或删除该组织的会员');if(Db::name('agent_subaccounts')->where('organization_id',$id)->whereNull('deleted_at')->count()>0)throw new \InvalidArgumentException('请先处理该组织的子账号');$operator=$this->requestOperator($request);Db::transaction(function()use($id,$node,$operator):void{ScoreTransfer::organizationAllocation($node,-(float)$node['credit_limit'],$operator);Db::name('organization_profit_shares')->where('parent_organization_id',$id)->delete();Db::name('organization_profit_shares')->where('child_organization_id',$id)->delete();Db::name('organization_accounts')->where('organization_id',$id)->delete();Db::name('organization_nodes')->where('id',$id)->delete();});return $this->reply(null,'组织已删除，分数已退回上级'); }
    public function adminCreateAccount(Request $request,int $organizationId): \think\response\Json { $node=Db::name('organization_nodes')->where('id',$organizationId)->whereNull('deleted_at')->find();if(!$node)throw new \InvalidArgumentException('组织不存在');$data=$this->accountPayload($request,$node);$password=(string)$data['_initial_password'];unset($data['_initial_password']);$data['created_at']=$data['updated_at'];$id=$this->createOrRestoreAccount($data);return $this->reply(['id'=>$id,'username'=>$data['username'],'initial_password'=>$password,'must_change_password'=>1],'管理员创建成功'); }
    public function adminUpdateAccount(Request $request,int $id): \think\response\Json { $current=Db::name('organization_accounts')->where('id',$id)->whereNull('deleted_at')->find();if(!$current)throw new \InvalidArgumentException('管理员不存在');$node=Db::name('organization_nodes')->where('id',(int)$current['organization_id'])->whereNull('deleted_at')->find();if(!$node)throw new \InvalidArgumentException('组织不存在');Db::name('organization_accounts')->where('id',$id)->update($this->accountPayload($request,$node,$current));return $this->reply(null,'管理员已更新'); }
    public function adminDeleteAccount(Request $request,int $id): \think\response\Json { Db::name('organization_accounts')->where('id',$id)->whereNull('deleted_at')->update(['deleted_at'=>date('Y-m-d H:i:s'),'status'=>0]);return $this->reply(null,'管理员已删除'); }

    public function adminProfitShare(Request $request,int $siteId): \think\response\Json
    {
        $this->site($siteId); return $this->reply($this->profitShares($siteId));
    }
    public function adminSaveProfitShare(Request $request,int $siteId,int $childId): \think\response\Json
    {
        $site=$this->site($siteId); return $this->reply($this->saveProfitShare($request,$site,$childId),'占成已保存');
    }

    public function profile(Request $request): \think\response\Json
    {
        $session=$this->agentSession($request);$site=$this->site((int)$session['site_id']);$organizationId=(int)($session['organization_id']??0);$node=$organizationId>0?Db::name('organization_nodes')->where('id',$organizationId)->whereNull('deleted_at')->find():OrganizationHierarchy::rootForSite((int)$site['id']);
        $level=(string)($node['level']??'director');return $this->reply(['site'=>['id'=>(int)$site['id'],'name'=>$site['name']],'organization'=>$node?['id'=>(int)$node['id'],'name'=>(string)($session['username']??$node['name']),'level'=>$level,'level_label'=>OrganizationHierarchy::LABELS[$level]??$level,'next_level'=>OrganizationHierarchy::nextLevel($level),'credit'=>OrganizationHierarchy::nodeCreditSummary((int)$node['id'])]:null,'permissions'=>(array)($session['permissions']??['*']),'username'=>(string)($session['username']??'')]);
    }

    public function agentIndex(Request $request): \think\response\Json
    {
        $session=$this->agentSession($request);$rootId=(int)($session['organization_id']??0);if($rootId<1)throw new \RuntimeException('当前账号尚未绑定层级数据');
        $requestedId=(int)$request->param('organization_id',0);$organizationId=$requestedId>0?$requestedId:$rootId;
        $current=Db::name('organization_nodes')->where('id',$organizationId)->where('site_id',(int)$session['site_id'])->whereNull('deleted_at')->find();
        $root=Db::name('organization_nodes')->where('id',$rootId)->where('site_id',(int)$session['site_id'])->whereNull('deleted_at')->find();
        if(!$current||!$root)throw new \RuntimeException('当前组织数据不存在');
        $visibleIds=OrganizationHierarchy::descendantIds($rootId);
        if(!in_array($organizationId,$visibleIds,true))throw new \InvalidArgumentException('无权查看该组织及其下级');
        $currentPayload=['id'=>(int)$current['id'],'parent_id'=>(int)$current['parent_id'],'name'=>$organizationId===$rootId?(string)($session['username']??$current['name']):(string)$current['name'],'node_name'=>(string)$current['name'],'level'=>$current['level'],'level_label'=>OrganizationHierarchy::LABELS[(string)$current['level']]??$current['level'],'next_level'=>OrganizationHierarchy::nextLevel((string)$current['level']),'credit'=>OrganizationHierarchy::nodeCreditSummary((int)$current['id']),'can_manage'=>$organizationId===$rootId];
        return $this->reply(array_merge(['current'=>$currentPayload,'root_organization_id'=>$rootId,'breadcrumbs'=>$this->breadcrumbs((int)$session['site_id'],$organizationId,$rootId)],$this->responseForSite((int)$session['site_id'],$organizationId)));
    }
    public function agentProfitShare(Request $request): \think\response\Json
    {
        $session=$this->agentSession($request); $node=OrganizationHierarchy::nodeForSession($session); if(!$node) throw new \RuntimeException('当前组织不存在'); return $this->reply($this->profitShares((int)$session['site_id'],(int)$node['id']));
    }
    public function agentSaveProfitShare(Request $request,int $childId): \think\response\Json
    {
        $session=$this->agentSession($request); $parent=OrganizationHierarchy::nodeForSession($session); if(!$parent) throw new \RuntimeException('当前组织不存在');
        $child=Db::name('organization_nodes')->where('id',$childId)->where('parent_id',(int)$parent['id'])->where('site_id',(int)$session['site_id'])->whereNull('deleted_at')->find(); if(!$child) throw new \InvalidArgumentException('只能设置直属下级占成');
        return $this->reply($this->saveProfitShare($request,$this->site((int)$session['site_id']),$childId,(int)$parent['id']),'占成已保存');
    }

    private function profitShares(int $siteId, ?int $parentId=null): array
    {
        $query=Db::name('organization_profit_shares')->where('site_id',$siteId)->where('status',1); if($parentId!==null)$query->where('parent_organization_id',$parentId);
        return $query->order('id asc')->select()->toArray();
    }
    private function saveProfitShare(Request $request,array $site,int $childId,?int $forcedParent=null): array
    {
        $child=Db::name('organization_nodes')->where('id',$childId)->where('site_id',(int)$site['id'])->whereNull('deleted_at')->find(); if(!$child) throw new \InvalidArgumentException('下级组织不存在');
        $parentId=$forcedParent??(int)$child['parent_id']; if((int)$child['parent_id']!==$parentId) throw new \InvalidArgumentException('上下级关系无效');
        $parent=$parentId>0?Db::name('organization_nodes')->where('id',$parentId)->where('site_id',(int)$site['id'])->whereNull('deleted_at')->find():null; if($parentId>0&&!$parent) throw new \InvalidArgumentException('上级组织不存在'); if($parentId===0&&(string)$child['level']!=='director') throw new \InvalidArgumentException('只有根总监可以直接归属平台');
        $siteCap=$this->siteMaxShareRate($site);$rate=(float)$request->post('share_rate',0); $max=(float)$request->post('max_share_rate',$rate); if($rate<0||$rate>$siteCap||$max<0||$max>$siteCap||$rate>$max+0.000001) throw new \InvalidArgumentException('占成不能超过本站点每级最高占成 '.number_format($siteCap,4,'.','').'%');
        $now=date('Y-m-d H:i:s'); $existing=Db::name('organization_profit_shares')->where('child_organization_id',$childId)->find(); $data=['tenant_id'=>(int)$site['tenant_id'],'site_id'=>(int)$site['id'],'parent_organization_id'=>$parentId,'child_organization_id'=>$childId,'max_share_rate'=>number_format($max,4,'.',''),'share_rate'=>number_format($rate,4,'.',''),'status'=>1,'updated_at'=>$now]; if($existing) Db::name('organization_profit_shares')->where('id',(int)$existing['id'])->update($data); else { $data['created_at']=$now; Db::name('organization_profit_shares')->insert($data); }
        return ['child_organization_id'=>$childId,'share_rate'=>number_format($rate,4,'.',''),'max_share_rate'=>number_format($max,4,'.',''),'site_max_share_rate'=>number_format($siteCap,4,'.','')];
    }
    public function agentCreateNode(Request $request): \think\response\Json
    {
        $session=$this->agentSession($request);
        $parent=Db::name('organization_nodes')->where('id',(int)($session['organization_id']??0))->whereNull('deleted_at')->find();
        if(!$parent||!OrganizationHierarchy::nextLevel((string)$parent['level']))throw new \InvalidArgumentException('当前层级不能继续创建下级');
        $site=$this->site((int)$session['site_id']);
        $data=$this->nodePayload($request,$site,null,$parent);
        $data['created_at']=$data['updated_at'];
        $operator=$this->requestOperator($request);
        $result=Db::transaction(function()use($request,$site,$parent,$data,$operator):array{
            $nodeId=(int)Db::name('organization_nodes')->insertGetId($data);
            $node=array_merge($data,['id'=>$nodeId,'balance'=>0]);
            ScoreTransfer::organizationAllocation($node,(float)$data['credit_limit'],$operator);
            OrganizationHierarchy::rebuildPath($nodeId);
            $account=$this->accountPayload($request,$node);
            $password=(string)$account['_initial_password'];
            unset($account['_initial_password']);
            $account['created_at']=$account['updated_at'];
            $accountId=$this->createOrRestoreAccount($account);
            $this->saveProfitShare($request,$site,$nodeId,(int)$parent['id']);
            return ['id'=>$accountId,'node_id'=>$nodeId,'username'=>$account['username'],'initial_password'=>$password,'must_change_password'=>1];
        });
        return $this->reply($result,'下级创建成功');
    }
    public function agentUpdateNode(Request $request,int $id): \think\response\Json
    {
        $session=$this->agentSession($request);$current=Db::name('organization_nodes')->where('id',$id)->where('parent_id',(int)($session['organization_id']??0))->whereNull('deleted_at')->find();if(!$current)throw new \InvalidArgumentException('只能修改直属下级');
        $parent=Db::name('organization_nodes')->where('id',(int)$current['parent_id'])->find();$site=$this->site((int)$session['site_id']);$data=$this->nodePayload($request,$site,$current,$parent);$operator=$this->requestOperator($request);
        $account=Db::name('organization_accounts')->where('organization_id',$id)->whereNull('deleted_at')->order('id asc')->find();if(!$account)throw new \InvalidArgumentException('当前下级登录账号不存在');
        $accountData=$this->accountPayload($request,array_merge($current,['permissions'=>$data['permissions']]),$account);
        Db::transaction(function()use($request,$site,$parent,$id,$current,$data,$account,$accountData,$operator):void{
            $delta=(float)$data['credit_limit']-(float)$current['credit_limit'];ScoreTransfer::organizationAllocation($current,$delta,$operator);Db::name('organization_nodes')->where('id',$id)->update($data);Db::name('organization_accounts')->where('id',(int)$account['id'])->update($accountData);$this->saveProfitShare($request,$site,$id,(int)$parent['id']);
        });
        return $this->reply(null,'下级已更新');
    }
    public function agentCreateAccount(Request $request,int $organizationId): \think\response\Json { $session=$this->agentSession($request);$node=Db::name('organization_nodes')->where('id',$organizationId)->where('parent_id',(int)($session['organization_id']??0))->whereNull('deleted_at')->find();if(!$node)throw new \InvalidArgumentException('只能管理直属下级账号');$data=$this->accountPayload($request,$node);$password=(string)$data['_initial_password'];unset($data['_initial_password']);$data['created_at']=$data['updated_at'];$id=$this->createOrRestoreAccount($data);return $this->reply(['id'=>$id,'username'=>$data['username'],'initial_password'=>$password,'must_change_password'=>1],'下级管理员创建成功'); }
    public function agentDeleteNode(Request $request,int $id): \think\response\Json { $session=$this->agentSession($request);$node=Db::name('organization_nodes')->where('id',$id)->where('parent_id',(int)($session['organization_id']??0))->whereNull('deleted_at')->find();if(!$node)throw new \InvalidArgumentException('只能删除直属下级');if(Db::name('organization_nodes')->where('parent_id',$id)->whereNull('deleted_at')->count()>0)throw new \InvalidArgumentException('请先处理该组织的下级');if(Db::name('site_users')->where('organization_id',$id)->whereNull('deleted_at')->count()>0)throw new \InvalidArgumentException('请先转移或删除该组织的会员');if(Db::name('agent_subaccounts')->where('organization_id',$id)->whereNull('deleted_at')->count()>0)throw new \InvalidArgumentException('请先处理该组织的子账号');$now=date('Y-m-d H:i:s');$operator=$this->requestOperator($request);Db::transaction(function()use($id,$node,$now,$operator):void{ScoreTransfer::organizationAllocation($node,-(float)$node['credit_limit'],$operator);Db::name('organization_nodes')->where('id',$id)->update(['deleted_at'=>$now,'status'=>0,'credit_limit'=>'0.00']);Db::name('organization_accounts')->where('organization_id',$id)->update(['deleted_at'=>$now,'status'=>0]);});return $this->reply(null,'直属下级已删除，分数已退回上级'); }
    public function agentUpdateAccount(Request $request,int $id): \think\response\Json { $session=$this->agentSession($request);$account=Db::name('organization_accounts')->where('id',$id)->whereNull('deleted_at')->find();$node=$account?Db::name('organization_nodes')->where('id',(int)$account['organization_id'])->where('parent_id',(int)($session['organization_id']??0))->whereNull('deleted_at')->find():null;if(!$account||!$node)throw new \InvalidArgumentException('只能管理直属下级账号');Db::name('organization_accounts')->where('id',$id)->update($this->accountPayload($request,$node,$account));return $this->reply(null,'下级管理员已更新'); }
    public function agentDeleteAccount(Request $request,int $id): \think\response\Json { $session=$this->agentSession($request);$account=Db::name('organization_accounts')->where('id',$id)->whereNull('deleted_at')->find();$node=$account?Db::name('organization_nodes')->where('id',(int)$account['organization_id'])->where('parent_id',(int)($session['organization_id']??0))->whereNull('deleted_at')->find():null;if(!$account||!$node)throw new \InvalidArgumentException('只能管理直属下级账号');Db::name('organization_accounts')->where('id',$id)->update(['status'=>0,'deleted_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);return $this->reply(null,'下级管理员已删除'); }
}
