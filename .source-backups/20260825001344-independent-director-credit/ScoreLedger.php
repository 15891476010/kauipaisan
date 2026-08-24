<?php
declare(strict_types=1);

namespace app\controller;

use app\service\ScoreTransfer;
use think\Request;
use think\facade\Cache;
use think\facade\Db;

final class ScoreLedger
{
    private function reply(mixed $data=null,string $message='ok',int $code=0): \think\response\Json{return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]);}
    private function session(Request $request): array
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));$session=$token!==''?Cache::get('token:'.$token):null;
        if(!is_array($session)||($session['scope']??'')!=='admin'||($session['admin_role']??'platform')!=='platform')throw new \RuntimeException('只有总平台管理员可以查看总账');return$session;
    }
    private function tenantId(array $session): int
    {
        $id=(int)($session['tenant_id']??0);if($id<1)$id=(int)(Db::name('tenants')->where('status',1)->order('id')->value('id')?:1);return$id;
    }
    private function operator(array $session): array{return['type'=>'platform_admin','id'=>(int)($session['user_id']??0),'name'=>(string)($session['username']??'')];}

    public function overview(Request $request): \think\response\Json
    {
        $session=$this->session($request);return$this->reply($this->overviewData($this->tenantId($session)));
    }
    public function dashboard(Request $request): \think\response\Json
    {
        $session=$this->session($request);$tenantId=$this->tenantId($session);$overview=$this->overviewData($tenantId);
        $today=date('Y-m-d');$todayStart=$today.' 00:00:00';$tomorrow=date('Y-m-d 00:00:00',strtotime('+1 day'));
        $todayRow=Db::name('organization_credit_ledger')->where('tenant_id',$tenantId)->where('created_at','>=',$todayStart)->where('created_at','<',$tomorrow)->field("COUNT(*) total,COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE 0 END),0) total_in,COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END),0) total_out")->find()?:[];

        $trendStart=date('Y-m-d 00:00:00',strtotime('-6 days'));
        $trendRows=Db::name('organization_credit_ledger')->where('tenant_id',$tenantId)->where('created_at','>=',$trendStart)->field("DATE(created_at) day,COUNT(*) total,COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE 0 END),0) total_in,COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END),0) total_out")->group('DATE(created_at)')->select()->toArray();
        $trendMap=[];foreach($trendRows as$row)$trendMap[(string)$row['day']]=$row;$trend=[];
        for($offset=6;$offset>=0;$offset--){$day=date('Y-m-d',strtotime('-'.$offset.' days'));$row=$trendMap[$day]??[];$in=(float)($row['total_in']??0);$out=(float)($row['total_out']??0);$trend[]=['day'=>$day,'total'=>(int)($row['total']??0),'total_in'=>$this->money($in),'total_out'=>$this->money($out),'net'=>$this->money($in-$out)];}

        $rootRows=Db::name('organization_nodes')->where('tenant_id',$tenantId)->where('parent_id',0)->whereNull('deleted_at')->field('site_id,COUNT(*) organization_count,COALESCE(SUM(credit_limit),0) allocated_score')->group('site_id')->select()->toArray();
        $organizationRows=Db::name('organization_nodes')->where('tenant_id',$tenantId)->whereNull('deleted_at')->field('site_id,COUNT(*) organization_count,COALESCE(SUM(balance),0) available_score')->group('site_id')->select()->toArray();
        $userRows=Db::name('site_users')->where('tenant_id',$tenantId)->whereNull('deleted_at')->field('site_id,COUNT(*) user_count,COALESCE(SUM(balance+credit_balance-used_balance),0) available_score,COALESCE(SUM(used_balance),0) locked_score')->group('site_id')->select()->toArray();
        $rootMap=$this->rowsByKey($rootRows,'site_id');$organizationMap=$this->rowsByKey($organizationRows,'site_id');$userMap=$this->rowsByKey($userRows,'site_id');$sites=[];
        foreach(Db::name('sites')->where('tenant_id',$tenantId)->whereNull('deleted_at')->order('id asc')->field('id,name,status')->select()->toArray()as$site){$siteId=(int)$site['id'];$root=$rootMap[$siteId]??[];$organizations=$organizationMap[$siteId]??[];$users=$userMap[$siteId]??[];$organizationAvailable=(float)($organizations['available_score']??0);$userAvailable=(float)($users['available_score']??0);$locked=(float)($users['locked_score']??0);$sites[]=['site_id'=>$siteId,'site_name'=>(string)$site['name'],'status'=>(int)$site['status'],'allocated_score'=>$this->money($root['allocated_score']??0),'organization_available'=>$this->money($organizationAvailable),'user_available'=>$this->money($userAvailable),'user_locked'=>$this->money($locked),'circulating_score'=>$this->money($organizationAvailable+$userAvailable+$locked),'organization_count'=>(int)($organizations['organization_count']??0),'user_count'=>(int)($users['user_count']??0)];}

        $levelLabels=['director'=>'总监','shareholder'=>'大股东','small_shareholder'=>'小股东','general_agent'=>'总代理','agent'=>'代理'];$levelRows=Db::name('organization_nodes')->where('tenant_id',$tenantId)->whereNull('deleted_at')->field('level,COUNT(*) account_count,COALESCE(SUM(credit_limit),0) credit_limit,COALESCE(SUM(balance),0) available_score')->group('level')->select()->toArray();$levelMap=$this->rowsByKey($levelRows,'level');$levels=[];
        foreach($levelLabels as$level=>$label){$row=$levelMap[$level]??[];$levels[]=['level'=>$level,'label'=>$label,'account_count'=>(int)($row['account_count']??0),'credit_limit'=>$this->money($row['credit_limit']??0),'available_score'=>$this->money($row['available_score']??0)];}

        $categoryRows=Db::name('organization_credit_ledger')->where('tenant_id',$tenantId)->where('created_at','>=',$todayStart)->where('created_at','<',$tomorrow)->field("category,COUNT(*) total,COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE 0 END),0) total_in,COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END),0) total_out")->group('category')->select()->toArray();
        foreach($categoryRows as&$row){$row['total']=(int)$row['total'];$row['total_in']=$this->money($row['total_in']??0);$row['total_out']=$this->money($row['total_out']??0);}unset($row);
        $recent=Db::name('organization_credit_ledger')->where('tenant_id',$tenantId)->order('id desc')->limit(12)->select()->toArray();foreach($recent as&$row)$this->appendNames($row);unset($row);
        $accounted=(float)$overview['available_score']+(float)$overview['organization_available']+(float)$overview['user_available']+(float)$overview['user_locked'];
        return$this->reply(['overview'=>array_merge($overview,['accounted_score'=>$this->money($accounted),'difference_score'=>$this->money((float)$overview['total_score']-$accounted)]),'today'=>['total'=>(int)($todayRow['total']??0),'total_in'=>$this->money($todayRow['total_in']??0),'total_out'=>$this->money($todayRow['total_out']??0),'net'=>$this->money((float)($todayRow['total_in']??0)-(float)($todayRow['total_out']??0))],'trend'=>$trend,'sites'=>$sites,'levels'=>$levels,'categories'=>$categoryRows,'recent'=>$recent,'counts'=>['sites'=>count($sites),'organizations'=>(int)array_sum(array_column($sites,'organization_count')),'users'=>(int)array_sum(array_column($sites,'user_count'))],'generated_at'=>date(DATE_ATOM)]);
    }
    private function overviewData(int $tenantId): array
    {
        $account=ScoreTransfer::platformAccount($tenantId);
        $organizationTotal=(float)Db::name('organization_nodes')->where('tenant_id',$tenantId)->whereNull('deleted_at')->sum('balance');
        $userLocked=(float)Db::name('site_users')->where('tenant_id',$tenantId)->whereNull('deleted_at')->sum('used_balance');$userTotal=(float)Db::name('site_users')->where('tenant_id',$tenantId)->whereNull('deleted_at')->sum('balance')+(float)Db::name('site_users')->where('tenant_id',$tenantId)->whereNull('deleted_at')->sum('credit_balance')-$userLocked;
        $allocated=(float)Db::name('organization_nodes')->where('tenant_id',$tenantId)->where('parent_id',0)->whereNull('deleted_at')->sum('credit_limit');
        return['total_score'=>$this->money($account['total_score']),'available_score'=>$this->money($account['balance']),'allocated_score'=>$this->money($allocated),'organization_available'=>$this->money($organizationTotal),'user_available'=>$this->money($userTotal),'user_locked'=>$this->money($userLocked)];
    }
    public function updateTotal(Request $request): \think\response\Json
    {
        $session=$this->session($request);$total=$request->put('total_score',$request->post('total_score'));if(!is_numeric($total))throw new \InvalidArgumentException('请输入正确的总平台分数');$note=trim((string)$request->put('note',$request->post('note','')));
        $result=Db::transaction(fn():array=>ScoreTransfer::adjustPlatformTotal($this->tenantId($session),(float)$total,$this->operator($session),$note?:null));return$this->reply($result,'总平台分数已更新');
    }
    public function index(Request $request): \think\response\Json
    {
        $session=$this->session($request);$query=Db::name('organization_credit_ledger')->where('tenant_id',$this->tenantId($session));
        $exact=['transaction_no','site_id','organization_id','account_type','account_id','related_user_id','related_bet_record_id','related_bet_detail_id','issue_no','direction','source_type','category','operator_type','operator_id','counterparty_account_type','counterparty_account_id'];
        foreach($exact as $field){$value=$request->param($field,null);if($value!==null&&$value!=='')$query->where($field,$value);}
        foreach(['reason','operator_name','note']as$field){$value=trim((string)$request->param($field,''));if($value!=='')$query->whereLike($field,'%'.$value.'%');}
        $keyword=trim((string)$request->param('keyword',''));if($keyword!=='')$query->where(function($q)use($keyword):void{$like='%'.$keyword.'%';$q->whereLike('transaction_no',$like)->whereOrLike('issue_no',$like)->whereOrLike('reason',$like)->whereOrLike('source_type',$like)->whereOrLike('operator_name',$like)->whereOrLike('note',$like);if(is_numeric($keyword))$q->whereOr('account_id',(int)$keyword)->whereOr('organization_id',(int)$keyword)->whereOr('related_user_id',(int)$keyword)->whereOr('related_bet_record_id',(int)$keyword);});
        $field=trim((string)$request->param('field',''));$value=trim((string)$request->param('value',''));$searchable=array_merge($exact,['reason','operator_name','note','balance_before','balance_after','amount']);if($field!==''&&$value!==''&&in_array($field,$searchable,true))$query->whereLike($field,'%'.$value.'%');
        $this->range($query,'amount',$request->param('amount_min'),$request->param('amount_max'));$this->range($query,'balance_before',$request->param('balance_before_min'),$request->param('balance_before_max'));$this->range($query,'balance_after',$request->param('balance_after_min'),$request->param('balance_after_max'));
        $start=trim((string)$request->param('start_time',''));if($start!=='')$query->where('created_at','>=',$start);$end=trim((string)$request->param('end_time',''));if($end!=='')$query->where('created_at','<=',$end);
        $summaryQuery=clone$query;$summary=$summaryQuery->field("COUNT(*) total,COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE 0 END),0) total_in,COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END),0) total_out")->find()?:[];
        $page=max(1,(int)$request->param('page',1));$size=min(200,max(1,(int)$request->param('page_size',50)));$list=$query->order('id desc')->page($page,$size)->select()->toArray();foreach($list as&$row)$this->appendNames($row);unset($row);
        return$this->reply(['list'=>$list,'total'=>(int)($summary['total']??0),'page'=>$page,'page_size'=>$size,'summary'=>['total'=>(int)($summary['total']??0),'total_in'=>$this->money($summary['total_in']??0),'total_out'=>$this->money($summary['total_out']??0),'net'=>$this->money((float)($summary['total_in']??0)-(float)($summary['total_out']??0))]]);
    }
    public function detail(Request $request,int $id): \think\response\Json
    {
        $session=$this->session($request);$row=Db::name('organization_credit_ledger')->where('id',$id)->where('tenant_id',$this->tenantId($session))->find();if(!$row)throw new \InvalidArgumentException('流水不存在');$this->appendNames($row);$row['metadata']=is_string($row['metadata']??null)?(json_decode((string)$row['metadata'],true)?:[]):($row['metadata']??[]);return$this->reply($row);
    }
    private function range(mixed $query,string $field,mixed $min,mixed $max): void{if($min!==null&&$min!==''&&is_numeric($min))$query->where($field,'>=',(float)$min);if($max!==null&&$max!==''&&is_numeric($max))$query->where($field,'<=',(float)$max);}
    private function rowsByKey(array $rows,string $key): array{$result=[];foreach($rows as$row)$result[$row[$key]]=$row;return$result;}
    private function appendNames(array &$row): void
    {
        $row['site_name']=(int)$row['site_id']>0?(string)(Db::name('sites')->where('id',(int)$row['site_id'])->value('name')?:''):'';$type=(string)$row['account_type'];$id=(int)$row['account_id'];
        $row['account_name']=match($type){'platform'=>'总平台','organization'=>(string)(Db::name('organization_nodes')->where('id',$id)->value('name')?:''),'user'=>(string)(Db::name('site_users')->where('id',$id)->value('username')?:''),default=>''};
        $counterType=(string)($row['counterparty_account_type']??'');$counterId=(int)($row['counterparty_account_id']??0);$row['counterparty_name']=match($counterType){'platform'=>'总平台','organization'=>(string)(Db::name('organization_nodes')->where('id',$counterId)->value('name')?:''),'user'=>(string)(Db::name('site_users')->where('id',$counterId)->value('username')?:''),default=>''};
        foreach(['amount','balance_before','balance_after']as$field)$row[$field]=$this->money($row[$field]??0);
    }
    private function money(mixed $value): string{return number_format((float)$value,2,'.','');}
}
