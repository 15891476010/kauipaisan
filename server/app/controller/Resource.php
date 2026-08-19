<?php
declare(strict_types=1);
namespace app\controller;

use think\Request;
use think\facade\Db;
use think\facade\Cache;
use app\service\LotteryHistorySync;

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
        if ($resource === 'site-admins') $query->where('status',1);
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
            $fields = match ($resource) { 'domains'=>['domain','domain_type'], 'admins','site-admins','site-users'=>['username','display_name','phone'], 'bet-records'=>['issue_no','source_text','status'], 'menus'=>['name','title','path'], 'audit-logs'=>['username','action','resource'], 'settings'=>['key'], default=>['name','code'] };
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
        if ($resource === 'agent-center') {
            $siteIds=array_values(array_map('intval',array_column($list,'id'))); $domainsBySite=[];
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
            }
        }
        if ($resource === 'site-users') {
            $ids=array_values(array_unique(array_filter(array_column($list,'site_id'))));
            $siteNames=$ids ? Db::name('sites')->whereIn('id',$ids)->column('name','id') : [];
            foreach ($list as &$siteUser) $siteUser['site_name']=$siteNames[$siteUser['site_id']] ?? '站点已删除';
            foreach ($list as &$siteUser) $this->appendBalances($siteUser);
        }
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
            $unitWin=(float)($detail['win_amount']??0)/max(1,count(array_filter($numbers, fn(string $number): bool => $this->numberWon($number,(string)($histories[$detail['lottery']]['numbers']??''),(string)($detail['source_text']??'')))));
            $detail['source_text']=$detail['source_text'] ?? '';
            $detail['play_type'] = (string)($stop['play_type'] ?? $detail['category'] ?? '未识别玩法');
            $detail['draw_numbers'] = (string)($histories[$detail['lottery']]['numbers'] ?? '');
            foreach ($numbers as $index=>$number) {
                $won=$record['opened'] && $this->numberWon($number,$detail['draw_numbers'],(string)$detail['source_text']);
                $expanded[] = array_merge($detail,[
                    'row_key'=>$detail['id'].'-'.$index, 'number_text'=>$number, 'number_count'=>1,
                    'hundreds'=>$number[0], 'tens'=>$number[1], 'units'=>$number[2],
                    'amount'=>number_format($unitAmount,2,'.',''),
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
        $draw=preg_replace('/\D/','',$drawNumbers) ?: '';
        if (strlen($draw)!==3) return false;
        if (str_contains($source,'组三')) return count(array_unique(str_split($draw)))===2 && count(array_unique(str_split($number)))===2 && count_chars($number,1)===count_chars($draw,1);
        if (str_contains($source,'组六')) return count(array_unique(str_split($draw)))===3 && count(array_unique(str_split($number)))===3 && count_chars($number,1)===count_chars($draw,1);
        return $number===$draw;
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
        $data=$request->put();
        $digits=[];
        foreach (['hundreds','tens','units'] as $field) {
            $value=trim((string)($data[$field] ?? ''));
            if ($value!=='' && !preg_match('/^[0-9]$/',$value)) throw new \InvalidArgumentException('百十个只能填写单个数字');
            $digits[]=$value;
        }
        $number=implode('',$digits);
        if ($number==='') throw new \InvalidArgumentException('至少填写一个号码');
        $amount=array_key_exists('amount',$data) ? (float)$data['amount'] : (float)$detail['amount'];
        if ($amount<0 || !is_finite($amount)) throw new \InvalidArgumentException('金额必须是非负数字');
        Db::transaction(function () use ($detail,$record,$number,$amount): void {
            Db::name('bet_details')->where('id',$detail['id'])->update(['number_text'=>$number,'amount'=>number_format($amount,2,'.','')]);
            Db::name('user_stop_drops')->where('bet_detail_id',$detail['id'])->update(['number_text'=>$number,'original_amount'=>number_format($amount,2,'.',''),'actual_amount'=>number_format($amount,2,'.','')]);
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
            $domainGroups=$this->domainGroups($data); $lotteryIds=$data['lottery_ids']??[]; unset($data['domain'], $data['agent_domain'], $data['user_domain'], $data['agent_domains'], $data['user_domains'], $data['lottery_ids'], $data['code'], $data['parent_id'], $data['level'], $data['site_id'], $data['username'], $data['display_name'], $data['phone'], $data['password']);
            $data['agent_id'] = (int)($data['agent_id'] ?? 1);
            if (!empty($data['manager_password'])) $data['manager_password'] = password_hash((string)$data['manager_password'], PASSWORD_DEFAULT);
            $siteId=Db::transaction(function () use ($data,$domainGroups,$lotteryIds): int {
                $this->assertDomainsAvailable($domainGroups);
                $siteId=Db::name('sites')->insertGetId($data); $site=array_merge($data,['id'=>$siteId]);
                $this->replaceSiteDomains($site,$domainGroups);
                $this->replaceSiteLotteries($siteId,(int)$data['tenant_id'],$lotteryIds);
                if (!empty($data['manager_username']) && !empty($data['manager_password'])) Db::name('site_admins')->insert(['tenant_id'=>$data['tenant_id'],'site_id'=>$siteId,'username'=>$data['manager_username'],'display_name'=>$data['manager_username'],'phone'=>$data['manager_phone']??null,'password'=>$data['manager_password'],'status'=>1,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
                return $siteId;
            });
            return $this->reply(['id'=>$siteId], 'created');
        }
        if ($resource === 'admins') { $data['user_type']='admin'; $data['display_name']=$data['display_name']??($data['name']??$data['username']??'管理员'); $data['password']=password_hash((string)($data['password']??bin2hex(random_bytes(6))),PASSWORD_DEFAULT); unset($data['name'],$data['code']); }
        if ($resource === 'site-users') { unset($data['name'],$data['code'],$data['domain'],$data['parent_id'],$data['manager_username'],$data['manager_password'],$data['manager_phone'],$data['total_balance'],$data['available_balance']); $data['tenant_id']=(int)($data['tenant_id']??1); $data['site_id']=$scopedSiteId ?? (int)($data['site_id']??0); if ($data['site_id']<1 || !Db::name('sites')->where('id',$data['site_id'])->whereNull('deleted_at')->find()) throw new \InvalidArgumentException('请选择有效站点'); if (trim((string)($data['username']??''))==='') throw new \InvalidArgumentException('请输入用户账号'); $data['display_name']=$data['display_name']??$data['username']; $this->normalizeBalances($data); $data['password']=password_hash((string)($data['password']??bin2hex(random_bytes(6))),PASSWORD_DEFAULT); }
        $id = Db::name($this->table($resource))->insertGetId($data);
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
            $managerUsername=trim((string)($data['manager_username']??''));
            $managerPassword=(string)($data['manager_password']??'');
            $managerPhone=trim((string)($data['manager_phone']??''));
            unset($data['domain'], $data['agent_domain'], $data['user_domain'], $data['agent_domains'], $data['user_domains'], $data['lottery_ids'], $data['code'], $data['parent_id'], $data['level'], $data['site_id'], $data['username'], $data['display_name'], $data['phone'], $data['password']);
            if ($managerPassword !== '') $data['manager_password'] = password_hash($managerPassword, PASSWORD_DEFAULT); else unset($data['manager_password']);
            Db::transaction(function () use ($id,$data,$domainGroups,$lotteryIds,$managerUsername,$managerPassword,$managerPhone): void {
                $site=Db::name('sites')->where('id',$id)->whereNull('deleted_at')->lock(true)->find();
                if (!$site) throw new \InvalidArgumentException('站点不存在');
                $this->assertDomainsAvailable($domainGroups,$id);
                Db::name('sites')->where('id',$id)->update($data);
                $site=array_merge($site,$data);
                if ($managerUsername !== '') {
                    $admin=Db::name('site_admins')->where('site_id',$id)->whereNull('deleted_at')->find();
                    $adminData=['username'=>$managerUsername,'display_name'=>$managerUsername,'phone'=>$managerPhone !== ''?$managerPhone:null,'status'=>(int)($site['status']??1),'updated_at'=>date('Y-m-d H:i:s')];
                    if ($managerPassword !== '') $adminData['password']=password_hash($managerPassword,PASSWORD_DEFAULT);
                    if ($admin) Db::name('site_admins')->where('id',$admin['id'])->update($adminData);
                    elseif ($managerPassword !== '') Db::name('site_admins')->insert(array_merge($adminData,['tenant_id'=>$site['tenant_id'],'site_id'=>$id,'created_at'=>date('Y-m-d H:i:s')]));
                }
                $this->replaceSiteDomains($site,$domainGroups);
                $this->replaceSiteLotteries($id,(int)$site['tenant_id'],$lotteryIds);
            });
            return $this->reply(null,'updated');
        }
        if (isset($data['password']) && $data['password']!=='') $data['password']=password_hash((string)$data['password'],PASSWORD_DEFAULT); else unset($data['password']);
        $update=Db::name($this->table($resource))->where('id',$id);
        if ($resource === 'site-users' && $scopedSiteId !== null) { unset($data['site_id']); $update->where('site_id',$scopedSiteId); }
        if ($resource === 'site-users') { unset($data['total_balance'],$data['available_balance']); $current=Db::name('site_users')->where('id',$id)->find(); if (!$current) throw new \InvalidArgumentException('用户不存在'); $this->normalizeBalances($data,$current); }
        $update->update($data);
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
            if ($resource === 'agent-center') { Db::name('site_admins')->where('site_id',$id)->update(['deleted_at'=>date('Y-m-d H:i:s')]); Db::name('site_users')->where('site_id',$id)->update(['deleted_at'=>date('Y-m-d H:i:s')]); }
        }
        else Db::name($this->table($resource))->where('id',$id)->delete();
        return $this->reply(null,'deleted');
    }
}
