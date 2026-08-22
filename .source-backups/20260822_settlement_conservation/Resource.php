<?php
declare(strict_types=1);
namespace app\controller;

use think\Request;
use think\facade\Db;
use think\facade\Cache;
use app\service\LotteryHistorySync;
use app\service\BetSettlement;
use app\service\AccountPresence;
use app\service\CreditLedger;
use app\service\ScoreTransfer;
use app\service\PasswordPolicy;

final class Resource
{
    private const TABLES = ['agents'=>'agents','sub-agents'=>'agents','sub_agents'=>'agents','agent-center'=>'sites','agent_center'=>'sites','sites'=>'sites','domains'=>'domains','admins'=>'admins','site-admins'=>'site_admins','site_users'=>'site_users','site-users'=>'site_users','bet-records'=>'bet_records','roles'=>'roles','menus'=>'menus','audit-logs'=>'audit_logs','audit_logs'=>'audit_logs','settings'=>'settings'];

    private function reply(mixed $data=null, string $message='ok', int $code=0): \think\response\Json
    {
        return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]);
    }

    private function normalize(string $resource): string
    {
        return str_replace('_', '-', strtolower(trim($resource)));
    }

    private function table(string $resource): string
    {
        $resource = $this->normalize($resource);
        if (!isset(self::TABLES[$resource])) throw new \InvalidArgumentException('unknown resource: '.$resource);
        return self::TABLES[$resource];
    }

    private function scopedSiteId(Request $request): ?int
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token !== '' ? Cache::get('token:'.$token) : null;
        if (!is_array($session) || ($session['admin_role'] ?? 'platform') === 'platform') return null;
        $siteId=(int)($session['site_id'] ?? 0);
        if ($siteId < 1) throw new \RuntimeException('当前管理员未绑定站点');
        return $siteId;
    }

    private function scoreOperator(Request $request): array
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));$session=$token!==''?Cache::get('token:'.$token):[];return['type'=>(($session['admin_role']??'platform')==='platform'?'platform_admin':'site_admin'),'id'=>(int)($session['user_id']??0),'name'=>(string)($session['username']??'')];
    }

    private function authorizeResource(string $resource, ?int $scopedSiteId): void
    {
        if ($scopedSiteId !== null && !in_array($resource,['site-users','bet-records'],true)) throw new \RuntimeException('当前代理仅可管理本站点用户和下单记录');
    }

    private function normalizeBalances(array &$data, ?array $current=null): void
    {
        foreach (['balance','credit_balance','used_balance'] as $field) {
            $value=array_key_exists($field,$data) ? $data[$field] : ($current[$field] ?? 0);
            if (!is_numeric($value) || (float)$value < 0) throw new \InvalidArgumentException('余额、信用余额和已用余额必须为非负数字');
            $data[$field]=number_format((float)$value,2,'.','');
        }
        $total=(float)$data['balance']+(float)$data['credit_balance'];
        if ((float)$data['used_balance'] > $total) throw new \InvalidArgumentException('已用余额不能大于总余额');
    }

    private function appendBalances(array &$user): void
    {
        $user['balance']=number_format((float)($user['balance'] ?? 0),2,'.','');
        $user['credit_balance']=number_format((float)($user['credit_balance'] ?? 0),2,'.','');
        $user['used_balance']=number_format((float)($user['used_balance'] ?? 0),2,'.','');
        $user['total_balance']=number_format((float)$user['balance']+(float)$user['credit_balance'],2,'.','');
        $user['available_balance']=number_format((float)$user['total_balance']-(float)$user['used_balance'],2,'.','');
    }

    private function siteCreditLimit(int $siteId): float
    {
        $settings=Db::name('sites')->where('id',$siteId)->value('settings');
        $decoded=is_string($settings)?json_decode($settings,true):(is_array($settings)?$settings:[]);
        return max(0,(float)($decoded['credit_limit']??0));
    }

    private function siteMaxShareRate(mixed $value): float
    {
        if (!is_numeric($value)) throw new \InvalidArgumentException('每级最高占成必须是数字');
        $rate=(float)$value;
        if ($rate<0 || $rate>100) throw new \InvalidArgumentException('每级最高占成必须在 0 到 100 之间');
        return $rate;
    }

    private function syncSiteShareCap(int $siteId,int $tenantId,float $cap): void
    {
        Db::name('organization_profit_shares')->where('site_id',$siteId)->where('share_rate','>',$cap)->update(['share_rate'=>number_format($cap,4,'.',''),'updated_at'=>date('Y-m-d H:i:s')]);
        Db::name('organization_profit_shares')->where('site_id',$siteId)->where('max_share_rate','>',$cap)->update(['max_share_rate'=>number_format($cap,4,'.',''),'updated_at'=>date('Y-m-d H:i:s')]);
        $root=Db::name('organization_nodes')->where('site_id',$siteId)->where('parent_id',0)->where('level','shareholder')->whereNull('deleted_at')->find();
        if (!$root) return;
        $existing=Db::name('organization_profit_shares')->where('child_organization_id',(int)$root['id'])->find();
        if ($existing) return;
        $now=date('Y-m-d H:i:s');
        Db::name('organization_profit_shares')->insert(['tenant_id'=>$tenantId,'site_id'=>$siteId,'parent_organization_id'=>0,'child_organization_id'=>(int)$root['id'],'max_share_rate'=>number_format($cap,4,'.',''),'share_rate'=>number_format($cap,4,'.',''),'status'=>1,'created_at'=>$now,'updated_at'=>$now]);
    }

    private function assertSiteCredit(int $siteId, float $credit, ?int $excludeUserId=null): void
    {
        $query=Db::name('site_users')->where('site_id',$siteId)->whereNull('deleted_at');
        if ($excludeUserId!==null) $query->where('id','<>',$excludeUserId);
        $allocated=(float)$query->sum('credit_balance');
        $limit=$this->siteCreditLimit($siteId);
        if ($allocated+$credit>$limit+0.000001) throw new \InvalidArgumentException('站点分数不足，无法分配给用户');
    }

    private function ensureSiteRootOrganization(array $site): array
    {
        $root=Db::name('organization_nodes')->where('site_id',(int)$site['id'])->where('parent_id',0)->where('level','shareholder')->whereNull('deleted_at')->lock(true)->find();
        if ($root) return $root;
        $now=date('Y-m-d H:i:s');
        $id=(int)Db::name('organization_nodes')->insertGetId([
            'tenant_id'=>(int)$site['tenant_id'],'site_id'=>(int)$site['id'],'parent_id'=>0,'level'=>'shareholder','depth'=>1,'path'=>'',
            'name'=>(string)$site['name'].' · 根股东','code'=>'SH-'.(int)$site['id'],'credit_limit'=>'0.00','balance'=>'0.00',
            'permissions'=>json_encode(['*'],JSON_UNESCAPED_UNICODE),'settings'=>json_encode([],JSON_UNESCAPED_UNICODE),
            'status'=>(int)($site['status']??1),'created_at'=>$now,'updated_at'=>$now,
        ]);
        Db::name('organization_nodes')->where('id',$id)->update(['path'=>'/'.$id.'/']);
        return Db::name('organization_nodes')->where('id',$id)->lock(true)->find();
    }

    private function allocateSiteCredit(array $site, float $creditLimit, array $operator): void
    {
        $root=$this->ensureSiteRootOrganization($site);
        $delta=round($creditLimit-(float)$root['credit_limit'],2);
        ScoreTransfer::organizationAllocation($root,$delta,$operator);
        Db::name('organization_nodes')->where('id',(int)$root['id'])->update([
            'credit_limit'=>number_format($creditLimit,2,'.',''),
            'name'=>(string)$site['name'].' · 根股东',
            'status'=>(int)($site['status']??1),
            'updated_at'=>date('Y-m-d H:i:s'),
        ]);
    }

    private function normalizeDomainGroup(mixed $items, string $legacyDomain=''): array
    {
        if (!is_array($items)) $items=$legacyDomain !== '' ? [['domain'=>$legacyDomain,'is_primary'=>1,'status'=>1]] : [];
        $normalized=[]; $seen=[]; $primaryAssigned=false;
        foreach ($items as $item) {
            if (is_string($item)) $item=['domain'=>$item];
            if (!is_array($item)) continue;
            $domain=strtolower(rtrim(trim((string)($item['domain']??'')),'/'));
            if ($domain==='' || isset($seen[$domain])) continue;
            $seen[$domain]=true;
            $isPrimary=(int)($item['is_primary']??0)===1 && !$primaryAssigned;
            if ($isPrimary) $primaryAssigned=true;
            $normalized[]=['domain'=>$domain,'is_primary'=>$isPrimary?1:0,'status'=>(int)($item['status']??1)===0?0:1];
        }
        if (!$primaryAssigned && $normalized) $normalized[0]['is_primary']=1;
        return $normalized;
    }

    private function domainGroups(array $data): array
    {
        $groups=[
            'agent'=>$this->normalizeDomainGroup($data['agent_domains']??null,trim((string)($data['agent_domain']??$data['domain']??''))),
            'user'=>$this->normalizeDomainGroup($data['user_domains']??null,trim((string)($data['user_domain']??''))),
        ];
        $seen=[];
        foreach ($groups as $items) foreach ($items as $item) {
            $key=strtolower($item['domain']);
            if (isset($seen[$key])) throw new \InvalidArgumentException('代理端域名和用户端域名不能重复');
            $seen[$key]=true;
        }
        return $groups;
    }

    private function assertDomainsAvailable(array $groups, ?int $siteId=null): void
    {
        $domains=[];
        foreach ($groups as $items) foreach ($items as $item) $domains[]=$item['domain'];
        if (!$domains) return;
        $query=Db::name('domains')->whereIn('domain',$domains);
        if ($siteId !== null) $query->where('site_id','<>',$siteId);
        $existing=$query->value('domain');
        if ($existing) throw new \InvalidArgumentException('域名“'.$existing.'”已被其他站点使用');
    }

    private function replaceSiteDomains(array $site, array $groups): void
    {
        $siteId=(int)$site['id']; $now=date('Y-m-d H:i:s'); $rows=[];
        Db::name('domains')->where('site_id',$siteId)->whereIn('domain_type',['agent','user'])->delete();
        foreach ($groups as $type=>$items) foreach ($items as $item) $rows[]=[
            'tenant_id'=>(int)$site['tenant_id'], 'agent_id'=>(int)$site['agent_id'], 'site_id'=>$siteId,
            'domain'=>$item['domain'], 'domain_type'=>$type, 'is_primary'=>$item['is_primary'], 'status'=>$item['status'],
            'created_at'=>$now, 'updated_at'=>$now,
        ];
        if ($rows) Db::name('domains')->insertAll($rows);
    }

    private function replaceSiteLotteries(int $siteId, int $tenantId, mixed $lotteryIds): void
    {
        if (!is_array($lotteryIds)) $lotteryIds=[];
        $ids=array_values(array_unique(array_filter(array_map('intval',$lotteryIds),static fn(int $id): bool => $id > 0)));
        $valid=$ids ? Db::name('lotteries')->whereIn('id',$ids)->where('tenant_id',$tenantId)->where('status',1)->whereNull('deleted_at')->column('id') : [];
        Db::name('site_lotteries')->where('site_id',$siteId)->delete();
        if (!$valid) return;
        $now=date('Y-m-d H:i:s'); $rows=[];
        foreach ($valid as $lotteryId) $rows[]=['tenant_id'=>$tenantId,'site_id'=>$siteId,'lottery_id'=>(int)$lotteryId,'created_at'=>$now];
        Db::name('site_lotteries')->insertAll($rows);
    }

    private function query(string $resource): \think\db\Query
    {
        $resource = $this->normalize($resource);
        $query = Db::name($this->table($resource));
        if ($resource === 'agents') $query->where('level', 1);
        if (in_array($resource, ['sub-agents','sub_agents'], true)) $query->where('level', 2);
        if (in_array($resource,['admins','site-admins','site-users','agent-center'],true)) $query->whereNull('deleted_at');
        return $query;
    }

    public function index(Request $request, string $resource): \think\response\Json
    {
        $resource = $this->normalize($resource);
        if ($resource === 'bet-records') {
            // Keep the management list current even when no CLI sync worker is running.
            $activeLotteries = Db::name('lotteries')->where('status', 1)->whereNull('deleted_at')->select()->toArray();
            foreach ($activeLotteries as $lottery) {
                try { (new LotteryHistorySync())->syncLottery($lottery); } catch (\Throwable $e) { /* stale data is preferable to failing the list */ }
            }
        }
        $query = $this->query($resource);
        $scopedSiteId=$this->scopedSiteId($request);
        $this->authorizeResource($resource,$scopedSiteId);
        if ($scopedSiteId !== null && in_array($resource,['site-users','bet-records'],true)) $query->where('site_id',$scopedSiteId);
        $keyword = trim((string)$request->param('keyword', ''));
        if ($scopedSiteId === null && in_array($resource,['site-users','site-admins','bet-records'],true) && $request->param('site_id') !== null && $request->param('site_id') !== '') $query->where('site_id',(int)$request->param('site_id'));
        if ($keyword !== '') {
            $fields = match ($resource) { 'domains'=>['domain','domain_type'], 'admins','site-admins','site-users'=>['username','display_name','phone'], 'bet-records'=>['issue_no','source_text','formatted_text','status'], 'menus'=>['name','title','path'], 'audit-logs'=>['username','action','resource'], 'settings'=>['key'], default=>['name','code'] };
            $query->where(function ($q) use ($fields,$keyword) { foreach($fields as $i=>$field) $i===0 ? $q->whereLike($field,'%'.$keyword.'%') : $q->whereOr($field,'like','%'.$keyword.'%'); });
        }
        if ($resource === 'bet-records') {
            $lottery = trim((string)$request->param('lottery', ''));
            if ($lottery !== '') {
                $ids = Db::name('user_stop_drops')->where('lottery', $lottery)->column('bet_detail_id');
                $recordIds = $ids ? Db::name('bet_details')->whereIn('id', $ids)->column('bet_record_id') : [];
                $query->whereIn('id', $recordIds ?: [0]);
            }
        }
        $total = (clone $query)->count();
        $list = $query->page(max(1,(int)$request->param('page',1)),min(100,max(1,(int)$request->param('page_size',20))))->order('id desc')->select()->toArray();
        if ($resource === 'audit-logs') {
            $userIds=array_values(array_unique(array_filter(array_map('intval',array_column($list,'user_id')))));
            $siteUsers=$userIds ? Db::name('site_users')->whereIn('id',$userIds)->field('id,site_id')->select()->toArray() : [];
            $siteByUser=[]; foreach ($siteUsers as $u) $siteByUser[(int)$u['id']]=(int)$u['site_id'];
            $adminNames=array_values(array_unique(array_filter(array_map('strval',array_column($list,'username')))));
            $siteAdmins=$adminNames ? Db::name('site_admins')->whereIn('username',$adminNames)->field('username,site_id')->select()->toArray() : [];
            $siteByAdmin=[]; foreach($siteAdmins as $a) $siteByAdmin[(string)$a['username']]=(int)$a['site_id'];
            $siteIds=array_values(array_unique(array_filter(array_map('intval',array_values($siteByUser)))));
            $siteIds=array_values(array_unique(array_merge($siteIds,array_filter(array_map('intval',array_values($siteByAdmin))))));
            $siteNames=$siteIds ? Db::name('sites')->whereIn('id',$siteIds)->column('name','id') : [];
            $agentIds=array_values(array_unique(array_filter(array_map('intval',array_column($list,'agent_id')))));
            $agentNames=$agentIds ? Db::name('agents')->whereIn('id',$agentIds)->column('name','id') : [];
            foreach ($list as &$log) { $sid=in_array((string)($log['resource']??''),['user','preview','place'],true)?($siteByUser[(int)($log['user_id']??0)]??0):($siteByAdmin[(string)($log['username']??'')]??0); $isPlatformLog=($log['resource']??'')==='admin'||(($log['resource']??'')==='audit_logs'&&($log['action']??'')==='clear'); $log['site_name']=$siteNames[$sid]??($isPlatformLog?'平台':'平台自有站点'); $log['agent_name']=$agentNames[(int)($log['agent_id']??0)]??'平台'; }
            unset($log);
        }
        if ($resource === 'agent-center') {
            $siteIds=array_values(array_map('intval',array_column($list,'id'))); $domainsBySite=[];
            $adminCounts=[];
            if ($siteIds) foreach (Db::name('site_admins')->whereIn('site_id',$siteIds)->whereNull('deleted_at')->field('site_id,COUNT(*) AS admin_count')->group('site_id')->select()->toArray() as $countRow) $adminCounts[(int)$countRow['site_id']]=(int)$countRow['admin_count'];
            $domainRows=$siteIds ? Db::name('domains')->whereIn('site_id',$siteIds)->whereIn('domain_type',['agent','user'])->field('id,site_id,domain,domain_type,is_primary,status')->order('domain_type asc,is_primary desc,id asc')->select()->toArray() : [];
            foreach ($domainRows as $domainRow) $domainsBySite[(int)$domainRow['site_id']][(string)$domainRow['domain_type']][]=['id'=>(int)$domainRow['id'],'domain'=>(string)$domainRow['domain'],'is_primary'=>(int)$domainRow['is_primary'],'status'=>(int)$domainRow['status']];
            foreach ($list as &$site) {
                $site['agent_domains']=$domainsBySite[(int)$site['id']]['agent']??[];
                $site['user_domains']=$domainsBySite[(int)$site['id']]['user']??[];
                $activeAgent=array_values(array_filter($site['agent_domains'],static fn(array $domain): bool => $domain['status']===1));
                $activeUser=array_values(array_filter($site['user_domains'],static fn(array $domain): bool => $domain['status']===1));
                $site['agent_domain']=(string)($activeAgent[0]['domain']??'');
                $site['user_domain']=(string)($activeUser[0]['domain']??'');
                $site['domain'] = $site['agent_domain'];
                $site['lottery_ids']=array_map('intval',Db::name('site_lotteries')->where('site_id',(int)$site['id'])->column('lottery_id'));
                $site['admin_count']=$adminCounts[(int)$site['id']]??0;
                $settings=$site['settings']??[]; $settings=is_string($settings)?json_decode($settings,true):(is_array($settings)?$settings:[]); $site['credit_limit']=number_format((float)($settings['credit_limit']??0),2,'.',''); $site['max_profit_share_rate']=number_format((float)($settings['max_profit_share_rate']??100),4,'.','');
            }
        }
        if ($resource === 'site-users') {
            $ids=array_values(array_unique(array_filter(array_column($list,'site_id'))));
            $siteNames=$ids ? Db::name('sites')->whereIn('id',$ids)->column('name','id') : [];
            foreach ($list as &$siteUser) $siteUser['site_name']=$siteNames[$siteUser['site_id']] ?? '站点已删除';
            foreach ($list as &$siteUser) $this->appendBalances($siteUser);
            AccountPresence::append($list,'site_user');
        }
        if ($resource === 'site-admins') {
            $ids=array_values(array_unique(array_filter(array_map('intval',array_column($list,'site_id')))));
            $siteNames=$ids ? Db::name('sites')->whereIn('id',$ids)->column('name','id') : [];
            foreach ($list as &$siteAdmin) $siteAdmin['site_name']=$siteNames[(int)$siteAdmin['site_id']]??'站点已删除';
            unset($siteAdmin);
            AccountPresence::append($list,'site_admin');
        }
        if ($resource === 'admins') AccountPresence::append($list,'platform_admin');
        if ($resource === 'bet-records') {
            $siteIds=array_values(array_unique(array_map('intval',array_column($list,'site_id'))));
            $userIds=array_values(array_unique(array_map('intval',array_column($list,'user_id'))));
            $recordIds=array_values(array_map('intval',array_column($list,'id')));
            $detailIds=$recordIds ? Db::name('bet_details')->whereIn('bet_record_id',$recordIds)->column('id','bet_record_id') : [];
            $lotteryByRecord=[];
            if ($detailIds) {
                $stops=Db::name('user_stop_drops')->whereIn('bet_detail_id',array_values($detailIds))->column('lottery','bet_detail_id');
                foreach ($detailIds as $recordId=>$detailId) if (!empty($stops[$detailId])) $lotteryByRecord[(int)$recordId]=(string)$stops[$detailId];
            }
            $siteNames=$siteIds ? Db::name('sites')->whereIn('id',$siteIds)->column('name','id') : [];
            $userNames=$userIds ? Db::name('site_users')->whereIn('id',$userIds)->column('username','id') : [];
            foreach ($list as &$betRecord) {
                $betRecord['site_name']=$siteNames[(int)$betRecord['site_id']] ?? '站点已删除';
                $betRecord['username']=$userNames[(int)$betRecord['user_id']] ?? '用户已删除';
                $betRecord['lottery']=$lotteryByRecord[(int)$betRecord['id']] ?? '';
                $betRecord['amount']=number_format((float)($betRecord['amount']??0),2,'.','');
                $betRecord['win_amount']=number_format((float)($betRecord['win_amount']??0),2,'.','');
            }
        }
        foreach ($list as &$row) { unset($row['password'], $row['manager_password']); }
        return $this->reply(['list'=>$list,'total'=>$total]);
    }

    public function auditDetail(Request $request, int $id): \think\response\Json
    {
        $this->scopedSiteId($request);
        $row=Db::name('audit_logs')->where('id',$id)->find();
        if (!$row) throw new \InvalidArgumentException('日志不存在');
        $payload=json_decode((string)($row['payload']??''),true); $row['payload']=is_array($payload)?$payload:[];
        $site=null;
        if (in_array((string)($row['resource']??''),['user','preview','place'],true) && (int)($row['user_id']??0)>0) $site=Db::name('site_users')->alias('u')->join('sites s','s.id=u.site_id')->where('u.id',(int)$row['user_id'])->field('s.id,s.name,s.agent_id')->find();
        elseif (!empty($row['username'])) $site=Db::name('site_admins')->alias('a')->join('sites s','s.id=a.site_id')->where('a.username',(string)$row['username'])->field('s.id,s.name,s.agent_id')->find();
        $isPlatformLog=($row['resource']??'')==='admin'||(($row['resource']??'')==='audit_logs'&&($row['action']??'')==='clear');
        $row['site_name']=$site['name']??($isPlatformLog?'平台':'平台自有站点');
        $row['agent_name']=((int)($row['agent_id']??0)>0)?(string)(Db::name('agents')->where('id',(int)$row['agent_id'])->value('name')?:'未知代理'):'平台';
        $ip=(string)($row['ip']??'');
        $row['ip_location']=filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)?'公网地址':'内网或本机地址';
        return $this->reply($row);
    }

    public function clearAuditLogs(Request $request): \think\response\Json
    {
        if ($this->scopedSiteId($request) !== null) throw new \RuntimeException('只有总平台管理员可以清除审计日志');
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token!==''?Cache::get('token:'.$token):null;
        if(!is_array($session)||($session['scope']??'')!=='admin'||($session['admin_role']??'platform')!=='platform') throw new \RuntimeException('只有总平台管理员可以清除审计日志');
        $username=trim((string)($session['username']??''));
        if($username===''&&!empty($session['user_id']))$username=(string)(Db::name('admins')->where('id',(int)$session['user_id'])->value('username')?:'');
        $cleared=Db::transaction(function()use($request,$session,$username):int{
            $clearable=Db::name('audit_logs')->whereRaw("(`action` <> 'clear' OR `action` IS NULL OR `resource` <> 'audit_logs' OR `resource` IS NULL)");
            $count=(int)(clone $clearable)->count();
            if($count>0)$clearable->delete();
            $payload=['cleared_count'=>$count,'_request'=>['method'=>'DELETE','path'=>'/'.trim((string)$request->pathinfo(),'/'),'host'=>(string)$request->host(),'referer'=>(string)$request->header('referer'),'user_agent'=>mb_substr((string)$request->header('user-agent'),0,500),'query'=>[],'body'=>[],'started_at'=>date('Y-m-d H:i:s'),'duration_ms'=>0,'status_code'=>200,'success'=>true,'response'=>['code'=>0,'message'=>'审计日志已清除','data'=>['cleared_count'=>$count]]]];
            Db::name('audit_logs')->insert(['tenant_id'=>isset($session['tenant_id'])?(int)$session['tenant_id']:null,'agent_id'=>null,'organization_id'=>null,'user_id'=>(int)($session['user_id']??0),'username'=>$username!==''?mb_substr($username,0,80):null,'action'=>'clear','resource'=>'audit_logs','ip'=>mb_substr((string)$request->ip(),0,45),'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>date('Y-m-d H:i:s')]);
            return $count;
        });
        return $this->reply(['cleared_count'=>$cleared],'审计日志已清除');
    }

    public function betDetails(Request $request, int $id): \think\response\Json
    {
        $scopedSiteId=$this->scopedSiteId($request);
        $this->authorizeResource('bet-records',$scopedSiteId);
        $recordQuery=Db::name('bet_records')->where('id',$id);
        if ($scopedSiteId !== null) $recordQuery->where('site_id',$scopedSiteId);
        $record=$recordQuery->find();
        if (!$record) throw new \InvalidArgumentException('下单记录不存在');
        $details=Db::name('bet_details')->where('bet_record_id',$id)->order('id asc')->select()->toArray();
        $detailIds=array_values(array_map('intval',array_column($details,'id')));
        $stops=$detailIds ? Db::name('user_stop_drops')->whereIn('bet_detail_id',$detailIds)->column('lottery,number_text,play_type,original_amount,actual_amount,stop_amount,original_odds,actual_odds,drop_odds','bet_detail_id') : [];
        $lotteries = [];
        foreach ($stops as $stop) { $name = trim((string)($stop['lottery'] ?? '')); if ($name !== '') $lotteries[$name] = true; }
        // Refresh pending records before presenting them so the drawer reflects the latest draw.
        if ((string)($record['status'] ?? '') === 'pending') {
            foreach (array_keys($lotteries) as $name) {
                $lottery = Db::name('lotteries')->where('name', $name)->where('status', 1)->whereNull('deleted_at')->find();
                if ($lottery) { try { (new LotteryHistorySync())->syncLottery($lottery); } catch (\Throwable $e) { /* detail remains pending when provider is unavailable */ } }
            }
            $record = $recordQuery->find() ?: $record;
            $details = Db::name('bet_details')->where('bet_record_id',$id)->order('id asc')->select()->toArray();
        }
        $histories = [];
        foreach (array_keys($lotteries) as $name) {
            $lottery = Db::name('lotteries')->where('name', $name)->whereNull('deleted_at')->find();
            if (!$lottery) continue;
            $history = Db::name('lottery_histories')->where('lottery_id',(int)$lottery['id'])->where('code',(string)$record['issue_no'])->find();
            $histories[$name] = ['name'=>$name, 'opened'=>is_array($history), 'numbers'=>$history['numbers'] ?? '', 'open_time'=>$history['open_time'] ?? null];
        }
        $record['lotteries'] = array_values($histories);
        $record['opened'] = (bool)array_reduce($histories, static fn(bool $carry, array $item): bool => $carry || $item['opened'], false);
        $record['draw_numbers'] = (string)(array_values(array_filter($histories, static fn(array $item): bool => $item['opened']))[0]['numbers'] ?? '');
        $expanded = [];
        foreach ($details as $detail) {
            $stop=$stops[(int)$detail['id']] ?? [];
            $numbers=preg_split('/\s+/', trim((string)($detail['number_text'] ?? ''))) ?: [];
            $numbers=array_values(array_filter($numbers, static fn(string $number): bool => preg_match('/^\d{3}$/',$number)===1));
            if ($numbers === []) continue;
            $detail['lottery']=(string)($stop['lottery'] ?? '');
            $numberCount=count($numbers);
            $unitAmount=(float)($detail['amount']??0)/$numberCount;
            $lotteryId=(int)Db::name('lotteries')->where('name',$detail['lottery'])->whereNull('deleted_at')->value('id');
            $detailOdds=(float)($detail['odds'] ?? 0);
            if ($detailOdds <= 0 && $lotteryId > 0) $detailOdds=(new BetSettlement())->oddsFor($lotteryId,(string)($detail['source_text'] ?? ''),$numberCount);
            $unitWin=(float)($detail['win_amount']??0)/max(1,count(array_filter($numbers, fn(string $number): bool => $this->numberWon($number,(string)($histories[$detail['lottery']]['numbers']??''),(string)($detail['source_text']??'')))));
            $detail['source_text']=$detail['source_text'] ?? '';
            $detail['play_type'] = (string)($stop['play_type'] ?? $detail['category'] ?? '未识别玩法');
            $detail['draw_numbers'] = (string)($histories[$detail['lottery']]['numbers'] ?? '');
            foreach ($numbers as $index=>$number) {
                $won=$record['opened'] && $this->numberWon($number,$detail['draw_numbers'],(string)$detail['source_text']);
                $expanded[] = array_merge($detail,[
                    'row_key'=>$detail['id'].'-'.$index, 'number_index'=>$index, 'number_text'=>$number, 'number_count'=>1,
                    'hundreds'=>$number[0], 'tens'=>$number[1], 'units'=>$number[2],
                    'amount'=>number_format($unitAmount,2,'.',''),
                    'odds'=>number_format($detailOdds,4,'.',''),
                    'win_amount'=>number_format($won?$unitWin:0,2,'.',''),
                    'result_status'=>$record['opened']?($won?'won':'unwon'):'pending',
                ]);
            }
        }
        $total=count($expanded); $page=max(1,(int)$request->param('page',1)); $pageSize=min(100,max(10,(int)$request->param('page_size',30)));
        return $this->reply(['record'=>$record,'list'=>array_slice($expanded,($page-1)*$pageSize,$pageSize),'total'=>$total,'page'=>$page,'page_size'=>$pageSize]);
    }

    private function numberWon(string $number, string $drawNumbers, string $source): bool
    {
        return (new BetSettlement())->numberMatches($number,$drawNumbers,$source);
    }

    public function updateBetDetail(Request $request, int $id): \think\response\Json
    {
        $scopedSiteId=$this->scopedSiteId($request);
        $this->authorizeResource('bet-records',$scopedSiteId);
        $detailQuery=Db::name('bet_details')->where('id',$id);
        if ($scopedSiteId !== null) $detailQuery->where('site_id',$scopedSiteId);
        $detail=$detailQuery->find();
        if (!$detail) throw new \InvalidArgumentException('下注明细不存在');
        $recordQuery=Db::name('bet_records')->where('id',(int)$detail['bet_record_id']);
        if ($scopedSiteId !== null) $recordQuery->where('site_id',$scopedSiteId);
        $record=$recordQuery->find();
        if (!$record) throw new \InvalidArgumentException('所属下单记录不存在');
        if ((string)($record['status'] ?? 'pending') !== 'pending') throw new \RuntimeException('已开奖或已结算注单不能修改');
        $data=$request->put();
        $numbers=preg_split('/\s+/',trim((string)$detail['number_text'])) ?: [];
        $numbers=array_values(array_filter($numbers,static fn(string $number): bool => preg_match('/^\d{3}$/',$number)===1));
        $numberIndex=(int)($data['number_index']??0);
        if (!isset($numbers[$numberIndex])) throw new \InvalidArgumentException('要修改的号码不存在');
        $number=trim((string)($data['number_text']??''));
        if (!preg_match('/^\d{3}$/',$number)) throw new \InvalidArgumentException('号码必须是三位数字');
        $oldUnitAmount=(float)$detail['amount']/max(1,count($numbers));
        $amount=array_key_exists('amount',$data) ? (float)$data['amount'] : $oldUnitAmount;
        if ($amount<0 || !is_finite($amount)) throw new \InvalidArgumentException('金额必须是非负数字');
        $numbers[$numberIndex]=$number; $numberText=implode(' ',$numbers);
        $detailAmount=(float)$detail['amount']-$oldUnitAmount+$amount;
        Db::transaction(function () use ($detail,$record,$numberText,$detailAmount): void {
            Db::name('bet_details')->where('id',$detail['id'])->update(['number_text'=>$numberText,'amount'=>number_format($detailAmount,2,'.','')]);
            Db::name('user_stop_drops')->where('bet_detail_id',$detail['id'])->update(['number_text'=>$numberText,'original_amount'=>number_format($detailAmount,2,'.',''),'actual_amount'=>number_format($detailAmount,2,'.','')]);
            $total=(float)Db::name('bet_details')->where('bet_record_id',$record['id'])->sum('amount');
            Db::name('bet_records')->where('id',$record['id'])->update(['amount'=>number_format($total,2,'.','')]);
        });
        return $this->reply(null,'updated');
    }

    public function create(Request $request, string $resource): \think\response\Json
    {
        $resource = $this->normalize($resource);
        if ($resource === 'bet-records') throw new \RuntimeException('下单记录为只读数据');
        $data = $request->post(); unset($data['id']);
        $scopedSiteId=$this->scopedSiteId($request);
        $this->authorizeResource($resource,$scopedSiteId);
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        if (in_array($resource,['agents','sub-agents','sub_agents','agent-center','sites','domains'],true)) $data['tenant_id']=(int)($data['tenant_id']??1);
        if ($resource === 'agents') { $data['level']=1; $data['parent_id']=0; }
        if (in_array($resource, ['sub-agents','sub_agents'], true)) {
            $data['level']=2; $data['parent_id']=(int)($data['parent_id']??0);
            if ($data['parent_id'] < 1 || !Db::name('agents')->where('id',$data['parent_id'])->where('level',1)->find()) throw new \InvalidArgumentException('二级代理必须归属有效的一级代理');
        }
        if ($resource === 'agent-center') {
            $domainGroups=$this->domainGroups($data); $lotteryIds=$data['lottery_ids']??[]; $creditLimit=max(0,(float)($data['credit_limit']??0)); $maxProfitShareRate=$this->siteMaxShareRate($data['max_profit_share_rate']??100); unset($data['domain'], $data['agent_domain'], $data['user_domain'], $data['agent_domains'], $data['user_domains'], $data['lottery_ids'], $data['credit_limit'], $data['max_profit_share_rate'], $data['code'], $data['parent_id'], $data['level'], $data['site_id'], $data['username'], $data['display_name'], $data['phone'], $data['password'], $data['manager_username'], $data['manager_password'], $data['manager_phone']);
            $data['settings']=json_encode(['credit_limit'=>$creditLimit,'max_profit_share_rate'=>$maxProfitShareRate],JSON_UNESCAPED_UNICODE);
            $data['agent_id'] = (int)($data['agent_id'] ?? 1);
            $operator=$this->scoreOperator($request);
            $siteId=Db::transaction(function () use ($data,$domainGroups,$lotteryIds,$creditLimit,$maxProfitShareRate,$operator): int {
                $this->assertDomainsAvailable($domainGroups);
                $siteId=Db::name('sites')->insertGetId($data); $site=array_merge($data,['id'=>$siteId]);
                $this->replaceSiteDomains($site,$domainGroups);
                $this->replaceSiteLotteries($siteId,(int)$data['tenant_id'],$lotteryIds);
                $this->allocateSiteCredit($site,$creditLimit,$operator);
                $this->syncSiteShareCap($siteId,(int)$data['tenant_id'],$maxProfitShareRate);
                return $siteId;
            });
            return $this->reply(['id'=>$siteId], 'created');
        }
        if ($resource === 'admins') { $data['user_type']='admin'; $data['display_name']=$data['display_name']??($data['name']??$data['username']??'管理员'); $password=PasswordPolicy::initial((string)($data['password']??''),(string)($data['username']??'')); $data['password']=password_hash($password,PASSWORD_DEFAULT); unset($data['name'],$data['code']); }
        if ($resource === 'site-admins') {
            unset($data['name'],$data['code'],$data['domain'],$data['parent_id']);
            $data['site_id']=(int)($data['site_id']??0); $site=Db::name('sites')->where('id',$data['site_id'])->whereNull('deleted_at')->find();
            if ($data['site_id']<1 || !$site) throw new \InvalidArgumentException('请选择有效站点');
            $data['tenant_id']=(int)$site['tenant_id'];
            $data['username']=trim((string)($data['username']??''));
            if ($data['username']==='') throw new \InvalidArgumentException('请输入管理员账号');
            if (Db::name('admins')->where('username',$data['username'])->whereNull('deleted_at')->find() || Db::name('site_admins')->where('username',$data['username'])->whereNull('deleted_at')->find()) throw new \InvalidArgumentException('管理员账号已存在');
            $password=(string)($data['password']??''); PasswordPolicy::assertValid($password,$data['username']);
            $data['display_name']=trim((string)($data['display_name']??''))?:$data['username'];
            $data['password']=password_hash($password,PASSWORD_DEFAULT);
        }
        if ($resource === 'site-users') { unset($data['name'],$data['code'],$data['domain'],$data['parent_id'],$data['manager_username'],$data['manager_password'],$data['manager_phone'],$data['total_balance'],$data['available_balance']); $data['tenant_id']=(int)($data['tenant_id']??1); $data['site_id']=$scopedSiteId ?? (int)($data['site_id']??0); if ($data['site_id']<1 || !Db::name('sites')->where('id',$data['site_id'])->whereNull('deleted_at')->find()) throw new \InvalidArgumentException('请选择有效站点'); $data['username']=trim((string)($data['username']??'')); if ($data['username']==='') throw new \InvalidArgumentException('请输入用户账号'); $data['display_name']=$data['display_name']??$data['username']; $this->normalizeBalances($data); $password=PasswordPolicy::initial((string)($data['password']??''),$data['username']); $data['password']=password_hash($password,PASSWORD_DEFAULT); }
        if($resource==='site-users'){
            $initialBalance=(float)$data['balance'];$initialCredit=(float)$data['credit_balance'];$data['balance']='0.00';$data['credit_balance']='0.00';$operator=$this->scoreOperator($request);
            $id=(int)Db::transaction(function()use($data,$initialBalance,$initialCredit,$operator):int{$id=(int)Db::name('site_users')->insertGetId($data);$user=Db::name('site_users')->where('id',$id)->find();ScoreTransfer::userAllocation($user,$initialBalance+$initialCredit,$operator);Db::name('site_users')->where('id',$id)->update(['balance'=>number_format($initialBalance,2,'.',''),'credit_balance'=>number_format($initialCredit,2,'.','')]);return$id;});
        }else $id = Db::name($this->table($resource))->insertGetId($data);
        return $this->reply(['id'=>$id], 'created');
    }

    public function update(Request $request, string $resource, int $id): \think\response\Json
    {
        $resource = $this->normalize($resource);
        if ($resource === 'bet-records') throw new \RuntimeException('下单记录为只读数据');
        $data=$request->put(); unset($data['id'],$data['created_at']); $data['updated_at']=date('Y-m-d H:i:s');
        $scopedSiteId=$this->scopedSiteId($request);
        $this->authorizeResource($resource,$scopedSiteId);
        if ($resource === 'agent-center') {
            $domainGroups=$this->domainGroups($data); $lotteryIds=$data['lottery_ids']??[];
            $siteBefore=Db::name('sites')->where('id',$id)->value('settings'); $siteSettings=is_string($siteBefore)?json_decode($siteBefore,true):(is_array($siteBefore)?$siteBefore:[]);
            $creditLimit=max(0,(float)($data['credit_limit']??($siteSettings['credit_limit']??0))); $maxProfitShareRate=$this->siteMaxShareRate($data['max_profit_share_rate']??($siteSettings['max_profit_share_rate']??100)); unset($data['credit_limit'],$data['max_profit_share_rate']);
            $siteSettings['credit_limit']=$creditLimit; $siteSettings['max_profit_share_rate']=$maxProfitShareRate; $data['settings']=json_encode($siteSettings,JSON_UNESCAPED_UNICODE);
            unset($data['domain'], $data['agent_domain'], $data['user_domain'], $data['agent_domains'], $data['user_domains'], $data['lottery_ids'], $data['code'], $data['parent_id'], $data['level'], $data['site_id'], $data['username'], $data['display_name'], $data['phone'], $data['password']);
            unset($data['manager_username'],$data['manager_password'],$data['manager_phone']);
            $operator=$this->scoreOperator($request);
            Db::transaction(function () use ($id,$data,$domainGroups,$lotteryIds,$creditLimit,$maxProfitShareRate,$operator): void {
                $site=Db::name('sites')->where('id',$id)->whereNull('deleted_at')->lock(true)->find();
                if (!$site) throw new \InvalidArgumentException('站点不存在');
                $this->assertDomainsAvailable($domainGroups,$id);
                Db::name('sites')->where('id',$id)->update($data);
                $site=array_merge($site,$data);
                $this->replaceSiteDomains($site,$domainGroups);
                $this->replaceSiteLotteries($id,(int)$site['tenant_id'],$lotteryIds);
                $this->allocateSiteCredit($site,$creditLimit,$operator);
                $this->syncSiteShareCap($id,(int)$site['tenant_id'],$maxProfitShareRate);
            });
            return $this->reply(null,'updated');
        }
        if (isset($data['password']) && $data['password']!=='') { $currentAccount=Db::name($this->table($resource))->where('id',$id)->find(); PasswordPolicy::assertValid((string)$data['password'],(string)($data['username']??$currentAccount['username']??''),(string)($currentAccount['password']??'')); $data['password']=password_hash((string)$data['password'],PASSWORD_DEFAULT); } else unset($data['password']);
        $update=Db::name($this->table($resource))->where('id',$id);
        if ($resource === 'site-admins') {
            unset($data['site_id'],$data['tenant_id']);
            $current=Db::name('site_admins')->where('id',$id)->whereNull('deleted_at')->find();
            if (!$current) throw new \InvalidArgumentException('管理员不存在');
            $username=trim((string)($data['username']??$current['username']));
            if ($username==='') throw new \InvalidArgumentException('请输入管理员账号');
            $duplicatePlatform=Db::name('admins')->where('username',$username)->whereNull('deleted_at')->find();
            $duplicateSite=Db::name('site_admins')->where('username',$username)->where('id','<>',$id)->whereNull('deleted_at')->find();
            if ($duplicatePlatform || $duplicateSite) throw new \InvalidArgumentException('管理员账号已存在');
            $data['username']=$username; $data['display_name']=trim((string)($data['display_name']??''))?:$username;
        }
        if ($resource === 'site-users' && $scopedSiteId !== null) { unset($data['site_id']); $update->where('site_id',$scopedSiteId); }
        if ($resource === 'site-users') { unset($data['total_balance'],$data['available_balance']); $current=Db::name('site_users')->where('id',$id)->find(); if (!$current) throw new \InvalidArgumentException('用户不存在'); $this->normalizeBalances($data,$current); }
        if($resource==='site-users'&&isset($current)){
            $scoreDelta=((float)$data['balance']-(float)$current['balance'])+((float)$data['credit_balance']-(float)$current['credit_balance']);$operator=$this->scoreOperator($request);
            Db::transaction(function()use($update,$data,$current,$scoreDelta,$operator):void{ScoreTransfer::userAllocation($current,$scoreDelta,$operator);$update->update($data);});
        }else $update->update($data);
        if (isset($data['status']) && (int)$data['status']===0 && in_array($resource,['site-admins','site-users','admins'],true)) {
            $accountType=match($resource){'site-admins'=>'site_admin','site-users'=>'site_user',default=>'platform_admin'};
            $now=date('Y-m-d H:i:s'); Db::name('account_sessions')->where('account_type',$accountType)->where('account_id',$id)->whereNull('logged_out_at')->update(['last_seen_at'=>$now,'logged_out_at'=>$now]);
        }
        return $this->reply(null,'updated');
    }

    public function delete(Request $request, string $resource, int $id): \think\response\Json
    {
        $resource = $this->normalize($resource);
        if ($resource === 'bet-records') throw new \RuntimeException('下单记录为只读数据');
        $scopedSiteId=$this->scopedSiteId($request);
        $this->authorizeResource($resource,$scopedSiteId);
        if (in_array($resource,['admins','site-admins','site-users','agent-center'],true)) {
            $delete=Db::name($this->table($resource))->where('id',$id);
            if ($resource === 'site-users' && $scopedSiteId !== null) $delete->where('site_id',$scopedSiteId);
            $delete->update(['deleted_at'=>date('Y-m-d H:i:s')]);
            if (in_array($resource,['admins','site-admins','site-users'],true)) {
                $accountType=match($resource){'site-admins'=>'site_admin','site-users'=>'site_user',default=>'platform_admin'};
                $now=date('Y-m-d H:i:s'); Db::name('account_sessions')->where('account_type',$accountType)->where('account_id',$id)->whereNull('logged_out_at')->update(['last_seen_at'=>$now,'logged_out_at'=>$now]);
            }
            if ($resource === 'agent-center') { Db::name('site_admins')->where('site_id',$id)->update(['deleted_at'=>date('Y-m-d H:i:s')]); Db::name('site_users')->where('site_id',$id)->update(['deleted_at'=>date('Y-m-d H:i:s')]); }
        }
        else Db::name($this->table($resource))->where('id',$id)->delete();
        return $this->reply(null,'deleted');
    }
}
