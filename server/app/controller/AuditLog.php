<?php
declare(strict_types=1);
namespace app\controller;

use app\service\OrganizationHierarchy;
use think\Request;
use think\facade\Cache;
use think\facade\Db;

final class AuditLog
{
    private function reply(mixed $data = null, string $message = 'ok', int $code = 0): \think\response\Json { return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]); }
    private function session(Request $request): array
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token!==''?Cache::get('token:'.$token):null;
        if (!is_array($session) || !in_array(($session['scope']??''),['agent','admin'],true)) throw new \RuntimeException('未登录或登录已过期');
        return $session;
    }
    public function index(Request $request): \think\response\Json
    {
        $session=$this->session($request); $query=Db::name('audit_logs'); $currentUsername='';
        if (($session['scope']??'')==='agent') {
            $query->where('agent_id',(int)($session['agent_id']??0));
            $accountTable=(string)($session['account_table']??'agent_admins');
            if (in_array($accountTable,['agent_admins','site_admins','organization_accounts'],true)) $currentUsername=(string)(Db::name($accountTable)->where('id',(int)($session['user_id']??0))->value('username')?:'');
            elseif ($accountTable==='sites') $currentUsername=(string)(Db::name('sites')->where('id',(int)($session['user_id']??0))->value('manager_username')?:'');
            if ((int)($session['site_id']??0)>0) {
                $siteId=(int)$session['site_id'];
                $userIds=OrganizationHierarchy::visibleUserIds($session);
                $userNames=$userIds?Db::name('site_users')->whereIn('id',$userIds)->column('username'):[];
                $node=OrganizationHierarchy::nodeForSession($session);$organizationIds=$node?OrganizationHierarchy::descendantIds((int)$node['id']):[];
                $adminNames=$organizationIds?Db::name('organization_accounts')->whereIn('organization_id',$organizationIds)->whereNull('deleted_at')->column('username'):[];
                if($node&&(int)$node['parent_id']===0)$adminNames=array_merge($adminNames,Db::name('site_admins')->where('site_id',$siteId)->column('username'));
                $adminNames=array_values(array_unique(array_merge($adminNames,$userNames)));
                $legacyName=(string)Db::name('sites')->where('id',$siteId)->value('manager_username');
                if ($legacyName!==''&&$node&&(int)$node['parent_id']===0) $adminNames[]=$legacyName;
                $query->where(function($q) use ($adminNames,$organizationIds): void {
                    $q->whereIn('organization_id',$organizationIds?:[0]);
                    $q->whereOr('username','in',$adminNames?:['__none__']);
                });
            }
        } elseif (($session['admin_role']??'platform')==='site') {
            $siteId=(int)($session['site_id']??0); $userIds=Db::name('site_users')->where('site_id',$siteId)->column('id'); $adminNames=Db::name('site_admins')->where('site_id',$siteId)->column('username');
            $query->where(function($q) use ($userIds,$adminNames): void { $q->whereIn('user_id',$userIds?:[0])->whereOr('username','in',$adminNames?:['__none__']); });
        }
        $username=trim((string)$request->param('username','')); if($username!=='') $query->whereLike('username','%'.$username.'%');
        $action=trim((string)$request->param('action','')); if($action!=='') $query->where('action',$action);
        $startDate=trim((string)$request->param('start_date','')); if($startDate!=='') $query->where('created_at','>=',$startDate.' 00:00:00');
        $endDate=trim((string)$request->param('end_date','')); if($endDate!=='') $query->where('created_at','<=',$endDate.' 23:59:59');
        $viewScope=trim((string)$request->param('view_scope','all'));
        if ($viewScope==='self' && $currentUsername!=='') $query->where('username',$currentUsername);
        elseif ($viewScope==='subordinate' && $currentUsername!=='') $query->where('username','<>',$currentUsername);
        $type=trim((string)$request->param('type','interception'));
        if ($type==='login') {
            $query->whereIn('action',['login_success','login_failed','logout']);
        } elseif ($type==='interception') {
            $query->where('resource','members')->where('action','update')->where(function($q): void {
                $q->whereLike('payload','%interception_rate%')->whereOrLike('payload','%offline_rebate%')->whereOrLike('payload','%permissions%')->whereOrLike('payload','%odds%');
            });
        } else {
            $query->where('resource','members')->whereIn('action',['create','update','delete'])->where(function($q): void {
                $q->whereNull('payload')->whereOr(function($nested): void {
                    $nested->whereNotLike('payload','%interception_rate%')->whereNotLike('payload','%offline_rebate%')->whereNotLike('payload','%permissions%')->whereNotLike('payload','%odds%');
                });
            });
        }
        $targetUsername=trim((string)$request->param('target_username',''));
        if ($targetUsername!=='' && (int)($session['site_id']??0)>0) {
            $targetIds=OrganizationHierarchy::visibleUserIds($session);$targetIds=$targetIds?Db::name('site_users')->whereIn('id',$targetIds)->whereLike('username','%'.$targetUsername.'%')->column('id'):[];
            $query->where(function($q) use ($targetIds,$targetUsername): void {
                foreach($targetIds as $targetId) $q->whereOrLike('payload','%/members/'.(int)$targetId.'%');
                $q->whereOrLike('payload','%'.$targetUsername.'%');
            });
        }
        $content=trim((string)$request->param('content',''));
        $contentField=match($content) { 'credit'=>'credit_balance','status'=>'account_state','password'=>'password','interception'=>'interception_rate',default=>'' };
        if ($contentField!=='') $query->whereLike('payload','%'.$contentField.'%');
        $page=max(1,(int)$request->param('page',1)); $size=min(100,max(1,(int)$request->param('page_size',40))); $total=(clone $query)->count();
        $list=$query->field('id,user_id,username,action,resource,ip,payload,created_at')->order('id desc')->page($page,$size)->select()->toArray();
        foreach($list as &$row) {
            if(empty($row['username']) && !empty($row['user_id'])) $row['username']=(string)(Db::name('site_users')->where('id',(int)$row['user_id'])->value('username')?:'');
            $payload=is_string($row['payload']??null)?json_decode((string)$row['payload'],true):(is_array($row['payload']??null)?$row['payload']:[]);
            $requestInfo=is_array($payload['_request']??null)?$payload['_request']:[]; $path=(string)($requestInfo['path']??''); $targetId=null;
            if (preg_match('~/members/(\d+)~',$path,$matches)) $targetId=(int)$matches[1];
            $targetUsername=$targetId?(string)(Db::name('site_users')->where('id',$targetId)->value('username')?:''):'';
            if ($targetUsername==='') $targetUsername=(string)($payload['username']??'');
            $changes=[]; $labels=['display_name'=>'代号','phone'=>'电话','credit_balance'=>'分数额度','status'=>'状态','account_state'=>'账号状态','remark'=>'备注','password'=>'密码','interception_rate'=>'拦货占成','offline_rebate'=>'赚水'];
            foreach($labels as $field=>$label) if(array_key_exists($field,$payload)) $changes[]=$label.'：'.($field==='password'?'已修改':(is_scalar($payload[$field])?(string)$payload[$field]:'已修改'));
            if (isset($payload['permissions'])) $changes[]='彩种权限/赚水已修改';
            if (isset($payload['odds'])) $changes[]='赔率/赚水已修改';
            $agent=(string)($requestInfo['user_agent']??''); $row['target_username']=$targetUsername;
            $row['content']=$changes?implode('；',$changes):$this->actionName((string)$row['action']); $row['before_value']='---'; $row['after_value']=$changes?implode('；',$changes):'---';
            $row['device']=preg_match('/Mobile|Android|iPhone|iPad/i',$agent)?'手机':'电脑'; unset($row['user_id'],$row['payload']);
        }
        return $this->reply(['list'=>$list,'total'=>$total,'page'=>$page,'page_size'=>$size]);
    }

    private function actionName(string $action): string { return match($action) { 'login_success'=>'登录成功','login_failed'=>'登录失败','logout'=>'退出登录','create'=>'新增账号','update'=>'修改账号','delete'=>'删除账号',default=>'操作' }; }
}
