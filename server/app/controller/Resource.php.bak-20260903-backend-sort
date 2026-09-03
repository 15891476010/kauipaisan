<?php
declare(strict_types=1);
namespace app\controller;

use think\Request;
use think\facade\Db;
use think\facade\Cache;
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
        $site=Db::name('sites')->where('id',$siteId)->field('id,tenant_id')->find();
        if($site){$account=ScoreTransfer::siteAccount((int)$site['tenant_id'],$siteId);if((float)$account['total_score']>0.000001)return max(0,(float)$account['total_score']);}
        $settings=Db::name('sites')->where('id',$siteId)->value('settings');
        $decoded=is_string($settings)?json_decode($settings,true):(is_array($settings)?$settings:[]);
        return max(0,(float)($decoded['credit_limit']??0));
    }

    /** The site's effective quota is the sum of its independent root director pools. */
    private function directorCreditTotal(int $siteId): float
    {
        return (float)Db::name('organization_nodes')->where('site_id',$siteId)->where('parent_id',0)->where('level','director')->whereNull('deleted_at')->sum('credit_limit');
    }

    private function siteMaxShareRate(mixed $value): float
    {
        if (!is_numeric($value)) throw new \InvalidArgumentException('每级最高占成必须是数字');
        $rate=(float)$value;
        if ($rate<0 || $rate>100) throw new \InvalidArgumentException('每级最高占成必须在 0 到 100 之间');
        return $rate;
    }

    private function waterRate(mixed $value): float
    {
        if (!is_numeric($value)) throw new \InvalidArgumentException('明水比例必须是数字');
        $rate=(float)$value;
        if ($rate<0 || $rate>1) throw new \InvalidArgumentException('明水比例必须在 0 到 1 之间');
        return round($rate,4);
    }

    private function syncSiteShareCap(int $siteId,int $tenantId,float $cap): void
    {
        Db::name('organization_profit_shares')->where('site_id',$siteId)->where('share_rate','>',$cap)->update(['share_rate'=>number_format($cap,4,'.',''),'updated_at'=>date('Y-m-d H:i:s')]);
        Db::name('organization_profit_shares')->where('site_id',$siteId)->where('max_share_rate','>',$cap)->update(['max_share_rate'=>number_format($cap,4,'.',''),'updated_at'=>date('Y-m-d H:i:s')]);
        $root=Db::name('organization_nodes')->where('site_id',$siteId)->where('parent_id',0)->where('level','director')->whereNull('deleted_at')->find();
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

    private function allocateSiteCredit(array $site, float $creditLimit, array $operator): void
    {
        // 站点分数池不再自动创建“根总监”占位节点；总监由 SaaS 管理员明确新增。
        ScoreTransfer::adjustSiteTotal($site,$creditLimit,$operator,'SaaS 设置站点总分');
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
        if (!$valid) {
            $this->syncSiteLotteryPermissions($siteId,$tenantId,[]);
            return;
        }
        $now=date('Y-m-d H:i:s'); $rows=[];
        foreach ($valid as $lotteryId) $rows[]=['tenant_id'=>$tenantId,'site_id'=>$siteId,'lottery_id'=>(int)$lotteryId,'created_at'=>$now];
        Db::name('site_lotteries')->insertAll($rows);
        $this->syncSiteLotteryPermissions($siteId,$tenantId,array_map('intval',$valid));
    }

    /**
     * Keep member lottery permissions aligned with the site's active lotteries.
     * Historical databases may contain permissions for an older duplicate lottery
     * id, so permissions are migrated by code/name before defaulting new entries.
     */
    private function syncSiteLotteryPermissions(int $siteId, int $tenantId, array $activeIds): void
    {
        $users=Db::name('site_users')->where('site_id',$siteId)->whereNull('deleted_at')->column('id');
        if (!$users) return;
        $activeIds=array_values(array_unique(array_map('intval',$activeIds)));
        $lotteries=Db::name('lotteries')->where('tenant_id',$tenantId)->whereNull('deleted_at')->field('id,name,code')->select()->toArray();
        $meta=[]; foreach ($lotteries as $lottery) $meta[(int)$lottery['id']]=$lottery;
        $activeMeta=[]; foreach ($activeIds as $lotteryId) if (isset($meta[$lotteryId])) $activeMeta[]=$meta[$lotteryId];
        $now=date('Y-m-d H:i:s');
        foreach (array_map('intval',$users) as $userId) {
            $saved=Db::name('user_lottery_permissions')->where('site_id',$siteId)->where('user_id',$userId)->select()->toArray();
            $byId=[]; $byKey=[]; $byName=[];
            foreach ($saved as $permission) {
                $permissionId=(int)$permission['lottery_id'];
                $byId[$permissionId]=$permission;
                if (isset($meta[$permissionId])) {
                    $key=(string)$meta[$permissionId]['code'].'\u0000'.(string)$meta[$permissionId]['name'];
                    $byKey[$key]=$permission;
                    $byName[(string)$meta[$permissionId]['name']]=$permission;
                }
            }
            Db::name('user_lottery_permissions')->where('site_id',$siteId)->where('user_id',$userId)->delete();
            if (!$activeMeta) continue;
            $rows=[]; $used=[];
            foreach ($activeMeta as $lottery) {
                $lotteryId=(int)$lottery['id']; $key=(string)$lottery['code'].'\u0000'.(string)$lottery['name'];
                $permission=$byId[$lotteryId]??null;
                if (!$permission) {
                    $candidate=$byKey[$key]??($byName[(string)$lottery['name']]??null);
                    if ($candidate) {
                        $legacyId=(int)$candidate['lottery_id'];
                        if (!isset($used[$legacyId])) $permission=$candidate;
                    }
                }
                $rows[]=[
                    'tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$userId,'lottery_id'=>$lotteryId,
                    'can_view'=>$permission ? ((int)$permission['can_view']===1?1:0) : 1,
                    'can_bet'=>$permission ? ((int)$permission['can_bet']===1?1:0) : 1,
                    'offline_rebate'=>$permission['offline_rebate']??'0.0000','created_at'=>$permission['created_at']??$now,'updated_at'=>$now,
                ];
                if ($permission) $used[(int)$permission['lottery_id']]=true;
            }
            Db::name('user_lottery_permissions')->insertAll($rows);
        }
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

    private function alertThreshold(mixed $value): float
    {
        if ($value === null || $value === '') return 0.0;
        $text = trim((string)$value);
        $multiplier = 1.0;
        if (str_ends_with($text, '万')) { $multiplier = 10000.0; $text = trim(substr($text, 0, -3)); }
        elseif (str_ends_with($text, '千')) { $multiplier = 1000.0; $text = trim(substr($text, 0, -3)); }
        if (!is_numeric($text) || (float)$text < 0) throw new \InvalidArgumentException('预警金额必须是非负数字');
        return (float)$text * $multiplier;
    }

    private function potentialWinAmount(array $detail): float
    {
        $amount = max(0.0, (float)($detail['amount'] ?? 0));
        $odds = max(0.0, (float)($detail['odds'] ?? 0));
        if ($amount <= 0 || $odds <= 0) return 0.0;
        $selectionCount = $this->detailSelectionCount($detail);
        $source = (string)($detail['source_text'] ?? '');
        $isPackage = $selectionCount > 1
            && preg_match('/(?<!\d)[0-9]{1,10}\s*(组三|组六)[一二两三四五六七八九1-9]码/u', $source) === 1;
        return $amount * $odds * ($isPackage ? $selectionCount : 1);
    }

    /** @param array<int,array<string,mixed>> $detailsByRecord */
    private function appendBetAlerts(array &$list, array $detailsByRecord, float $betThreshold, float $winThreshold): void
    {
        foreach ($list as &$record) {
            $recordId = (int)($record['id'] ?? 0);
            $pending = strtolower((string)($record['status'] ?? '')) === 'pending';
            $potential = 0.0;
            $editable = false;
            foreach ($detailsByRecord[$recordId] ?? [] as $detail) {
                $potential += $this->potentialWinAmount($detail);
                $editable = $editable || preg_match('/(?:^|\s)\d{3}(?:\s|$)/', (string)($detail['number_text'] ?? '')) === 1;
            }
            $record['potential_win_amount'] = number_format($potential, 2, '.', '');
            $record['batch_editable'] = $editable;
            $reasons = [];
            if ($pending && $betThreshold > 0 && (float)($record['amount'] ?? 0) >= $betThreshold) $reasons[] = 'bet_amount';
            if ($pending && $winThreshold > 0 && $potential >= $winThreshold) $reasons[] = 'potential_win';
            $record['alert_reasons'] = $reasons;
            $record['alert_level'] = $reasons === [] ? '' : (count($reasons) > 1 ? 'danger' : $reasons[0]);
        }
        unset($record);
    }

    /**
     * Simulate one exact three-digit draw against the filtered pending records.
     * Probabilities are ratios of pending order count and order amount, not a
     * claim about the lottery's own draw probability.
     * @return array<string,mixed>
     */
    private function simulateBetNumber(\think\db\Query $baseQuery, string $lottery, string $number): array
    {
        $pendingQuery = clone $baseQuery;
        $pendingQuery->where('status', 'pending');
        $pendingRows = $pendingQuery->field('id,amount')->select()->toArray();
        $ids = array_values(array_map('intval', array_column($pendingRows, 'id')));
        if ($ids === []) return ['number' => $number, 'total' => 0, 'win_count' => 0, 'lose_count' => 0, 'win_probability' => '0.00', 'lose_probability' => '0.00', 'total_amount' => '0.00', 'win_amount' => '0.00', 'lose_amount' => '0.00', 'win_amount_probability' => '0.00', 'lose_amount_probability' => '0.00'];
        $details = Db::name('bet_details')->alias('d')->leftJoin('user_stop_drops s', 's.bet_detail_id=d.id')
            ->whereIn('d.bet_record_id', $ids)->where('s.lottery', $lottery)
            ->field('d.bet_record_id,d.number_text,d.source_text,d.amount,d.odds')->select()->toArray();
        $byRecord = [];
        foreach ($details as $detail) $byRecord[(int)$detail['bet_record_id']][] = $detail;
        $winIds = [];
        foreach ($ids as $recordId) {
            foreach ($byRecord[$recordId] ?? [] as $detail) {
                $tokens = preg_split('/\s+/', trim((string)($detail['number_text'] ?? ''))) ?: [];
                $tokens = array_values(array_filter($tokens, static fn(string $token): bool => trim($token) !== ''));
                if ($tokens === []) $tokens = [(string)($detail['source_text'] ?? '')];
                $source = (string)($detail['source_text'] ?? '');
                if (array_filter($tokens, fn(string $token): bool => $this->numberWon($token, $number, $source)) !== []) {
                    $winIds[$recordId] = true;
                    break;
                }
            }
        }
        $totalAmount = 0.0; $winAmount = 0.0;
        foreach ($pendingRows as $row) {
            $amount = max(0.0, (float)($row['amount'] ?? 0));
            $totalAmount += $amount;
            if (isset($winIds[(int)$row['id']])) $winAmount += $amount;
        }
        $total = count($pendingRows); $winCount = count($winIds); $loseCount = $total - $winCount; $loseAmount = $totalAmount - $winAmount;
        return [
            'number' => $number, 'total' => $total, 'win_count' => $winCount, 'lose_count' => $loseCount,
            'win_probability' => number_format($total > 0 ? $winCount * 100 / $total : 0, 2, '.', ''),
            'lose_probability' => number_format($total > 0 ? $loseCount * 100 / $total : 0, 2, '.', ''),
            'total_amount' => number_format($totalAmount, 2, '.', ''), 'win_amount' => number_format($winAmount, 2, '.', ''), 'lose_amount' => number_format(max(0, $loseAmount), 2, '.', ''),
            'win_amount_probability' => number_format($totalAmount > 0 ? $winAmount * 100 / $totalAmount : 0, 2, '.', ''),
            'lose_amount_probability' => number_format($totalAmount > 0 ? max(0, $loseAmount) * 100 / $totalAmount : 0, 2, '.', ''),
        ];
    }

    public function index(Request $request, string $resource): \think\response\Json
    {
        $resource = $this->normalize($resource);
        // SaaS 的“管理员”页面统一展示平台管理员和各站点后台管理员；
        // 站点后台账号不再从代理中心单独进入维护。
        if ($resource === 'admins' && $this->scopedSiteId($request) === null) {
            $keyword=trim((string)$request->param('keyword',''));
            $platform=Db::name('admins')->whereNull('deleted_at');
            $site=Db::name('site_admins')->whereNull('deleted_at');
            $filterSiteId=(int)$request->param('site_id',0);
            if($filterSiteId>0)$site->where('site_id',$filterSiteId);
            if($keyword!==''){
                foreach([$platform,$site] as $query)$query->where(function($q)use($keyword){$q->whereLike('username','%'.$keyword.'%')->whereOr('display_name','like','%'.$keyword.'%')->whereOr('phone','like','%'.$keyword.'%');});
            }
            // 平台 admins 表没有 phone，使用空值别名与 site_admins 的返回结构对齐。
            $platformRows=$platform->field("id,username,display_name,'' AS phone,status,created_at,last_login_at,last_login_ip,last_login_location")->select()->toArray();
            $siteRows=$site->field('id,site_id,username,display_name,phone,status,created_at,last_login_at')->select()->toArray();
            $siteIds=array_values(array_unique(array_filter(array_map('intval',array_column($siteRows,'site_id')))));
            $siteNames=$siteIds?Db::name('sites')->whereIn('id',$siteIds)->column('name','id'):[];
            foreach($platformRows as &$row){$row['site_id']=null;$row['site_name']='平台';$row['account_table']='admins';$row['scope_label']='平台管理员';}unset($row);
            foreach($siteRows as &$row){$row['site_name']=$siteNames[(int)$row['site_id']]??'站点已删除';$row['account_table']='site_admins';$row['scope_label']='站点后台管理员';}unset($row);
            $list=array_merge($platformRows,$siteRows);
            usort($list,static fn(array $a,array $b):int=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')) ?: ((int)$b['id']-(int)$a['id']));
            AccountPresence::append($platformRows,'platform_admin'); AccountPresence::append($siteRows,'site_admin');
            $presence=[];foreach(array_merge($platformRows,$siteRows) as $row)$presence[(string)$row['account_table'].'#'.(int)$row['id']]=$row;
            foreach($list as &$row){$key=(string)$row['account_table'].'#'.(int)$row['id'];if(isset($presence[$key]))$row=array_merge($row,$presence[$key]);}unset($row);
            $total=count($list);$page=max(1,(int)$request->param('page',1));$size=min(100,max(1,(int)$request->param('page_size',20)));$list=array_slice($list,($page-1)*$size,$size);
            foreach($list as &$row)unset($row['password']);unset($row);
            return $this->reply(['list'=>$list,'total'=>$total]);
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
        $betAlertThreshold = 0.0; $winAlertThreshold = 0.0; $checkNumber = ''; $numberSimulation = null;
        if ($resource === 'bet-records') {
            $betAlertThreshold = $this->alertThreshold($request->param('bet_alert_threshold'));
            $winAlertThreshold = $this->alertThreshold($request->param('win_alert_threshold'));
            $candidateNumber = preg_replace('/\D/', '', (string)$request->param('check_number', '')) ?? '';
            if ($candidateNumber !== '') {
                if (strlen($candidateNumber) !== 3) throw new \InvalidArgumentException('试算号码必须是三位数字');
                $checkNumber = $candidateNumber;
            }
        }
        $simulationQuery = clone $query;
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
                $settings=$site['settings']??[]; $settings=is_string($settings)?json_decode($settings,true):(is_array($settings)?$settings:[]); $account=ScoreTransfer::siteAccount((int)$site['tenant_id'],(int)$site['id']); $site['credit_limit']=number_format((float)$account['total_score'],2,'.',''); $site['site_available_score']=number_format((float)$account['balance'],2,'.',''); $site['director_allocated_score']=number_format($this->directorCreditTotal((int)$site['id']),2,'.',''); $site['max_profit_share_rate']=number_format((float)($settings['max_profit_share_rate']??100),4,'.',''); $site['water_rate']=number_format((float)($settings['water_rate']??$settings['dark_water_rate']??0.085),4,'.','');
            }
        }
        if ($resource === 'site-users') {
            $ids=array_values(array_unique(array_filter(array_column($list,'site_id'))));
            $siteNames=$ids ? Db::name('sites')->whereIn('id',$ids)->column('name','id') : [];
            $organizationIds=array_values(array_unique(array_filter(array_map('intval',array_column($list,'organization_id')))));
            $organizationNames=$organizationIds?Db::name('organization_nodes')->whereIn('id',$organizationIds)->whereNull('deleted_at')->column('name','id'):[];
            $roots=$ids?Db::name('organization_nodes')->whereIn('site_id',$ids)->where('parent_id',0)->where('level','director')->whereNull('deleted_at')->field('id,site_id,name')->select()->toArray():[];
            $rootsBySite=[];foreach($roots as $root)$rootsBySite[(int)$root['site_id']]=$root;
            foreach ($list as &$siteUser) {
                $siteId=(int)$siteUser['site_id'];$organizationId=(int)($siteUser['organization_id']??0);
                $siteUser['site_name']=$siteNames[$siteId] ?? '站点已删除';
                $siteUser['organization_name']=$organizationId>0?($organizationNames[$organizationId]??'所属层级已删除'):'未归属（结算归根总监）';
                $siteUser['settlement_organization_id']=$organizationId>0?$organizationId:(int)($rootsBySite[$siteId]['id']??0);
                $siteUser['settlement_organization_name']=$organizationId>0?($organizationNames[$organizationId]??''):((string)($rootsBySite[$siteId]['name']??''));
                $siteUser['assignment_status']=$organizationId>0?'assigned':'unassigned';
            }
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
            $detailRows=$recordIds ? Db::name('bet_details')->whereIn('bet_record_id',$recordIds)->field('id,bet_record_id,status,win_amount,matched_count,number_text,amount,odds,source_text')->select()->toArray() : [];
            $detailIds=[]; $detailsByRecord=[];
            foreach ($detailRows as $detailRow) {
                $recordId=(int)$detailRow['bet_record_id'];
                // Keep the first detail per record for the list's lottery label;
                // detailsByRecord still retains every detail for win-status aggregation.
                if (!isset($detailIds[$recordId])) $detailIds[$recordId]=(int)$detailRow['id'];
                $detailsByRecord[$recordId][]=$detailRow;
            }
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
                $recordId=(int)$betRecord['id'];
                $recordStatus=(string)($betRecord['status']??'pending');
                if ($recordStatus==='refunded') {
                    $betRecord['win_status']='refunded';
                } elseif ($recordStatus==='pending' || (int)($betRecord['sealed']??0)===1) {
                    $betRecord['win_status']='pending';
                } else {
                    $betRecord['win_status']=$this->classifyWinStatus($detailsByRecord[$recordId]??[],(float)($betRecord['win_amount']??0));
                }
            }
            $this->appendBetAlerts($list, $detailsByRecord, $betAlertThreshold, $winAlertThreshold);
            if ($checkNumber !== '' && $lottery !== '') $numberSimulation = $this->simulateBetNumber($simulationQuery, $lottery, $checkNumber);
        }
        foreach ($list as &$row) { unset($row['password'], $row['manager_password']); }
        $payload = ['list'=>$list,'total'=>$total];
        if ($resource === 'bet-records') $payload['number_simulation'] = $numberSimulation;
        return $this->reply($payload);
    }

    private function detailSelectionCount(array $detail): int
    {
        $tokens=preg_split('/\s+/',trim((string)($detail['number_text']??'')))?:[];
        $tokens=array_values(array_filter($tokens,static fn(string $token): bool => trim($token)!==''));
        return max(1,count($tokens));
    }

    private function detailMatchedCount(array $detail,int $selectionCount): ?int
    {
        $stored=$detail['matched_count']??null;
        if ($stored!==null && is_numeric($stored)) return max(0,min($selectionCount,(int)$stored));
        $win=(float)($detail['win_amount']??0);
        if ($win<=0) return 0;
        $amount=(float)($detail['amount']??0); $odds=(float)($detail['odds']??0);
        if ($amount>0 && $odds>0) {
            $source=(string)($detail['source_text']??'');
            $isPackage=$selectionCount>1 && preg_match('/(?<!\d)[0-9]{1,10}\s*(组三|组六)[一二两三四五六七八九1-9]码/u',$source)===1;
            $unit=$isPackage?$amount:($amount/$selectionCount);
            $unit*=$odds;
            if ($unit>0) return max(0,min($selectionCount,(int)round($win/$unit)));
        }
        return $selectionCount===1 && (string)($detail['status']??'')==='won' ? 1 : null;
    }

    private function classifyWinStatus(array $details,float $recordWin): string
    {
        if ($details===[]) return $recordWin>0?'full':'none';
        $total=0; $matched=0; $unknown=false;
        foreach ($details as $detail) {
            if ((string)($detail['status']??'')==='pending') { $unknown=true; continue; }
            $count=$this->detailSelectionCount($detail); $total+=$count;
            $hits=$this->detailMatchedCount($detail,$count);
            if ($hits===null) $unknown=true; else $matched+=$hits;
        }
        if ($matched===0) return 'none';
        if (!$unknown && $total>0 && $matched>=$total) return 'full';
        return 'partial';
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
        // A multi-lottery submission is stored as one bet_record per lottery.
        // The detail drawer represents the order, so load all sibling records
        // from the same submission instead of showing only the clicked lottery.
        $recordIds=[$id];
        $submissionId=(int)($record['submission_id']??0);
        if ($submissionId>0) {
            $siblingQuery=Db::name('bet_records')->where('submission_id',$submissionId)->where('site_id',(int)$record['site_id']);
            $recordIds=array_values(array_map('intval',$siblingQuery->column('id')));
            if ($recordIds===[]) $recordIds=[$id];
        }
        $details=Db::name('bet_details')->whereIn('bet_record_id',$recordIds)->order('id asc')->select()->toArray();
        $detailIds=array_values(array_map('intval',array_column($details,'id')));
        $stops=$detailIds ? Db::name('user_stop_drops')->whereIn('bet_detail_id',$detailIds)->column('lottery,number_text,play_type,original_amount,actual_amount,stop_amount,original_odds,actual_odds,drop_odds','bet_detail_id') : [];
        $lotteries = [];
        foreach ($stops as $stop) { $name = trim((string)($stop['lottery'] ?? '')); if ($name !== '') $lotteries[$name] = true; }
        $recordRows=Db::name('bet_records')->whereIn('id',$recordIds)->select()->toArray();
        if ($recordRows!==[]) {
            $record['bet_count']=array_sum(array_map(static fn(array $row): int=>(int)($row['bet_count']??0),$recordRows));
            $record['amount']=number_format(array_sum(array_map(static fn(array $row): float=>(float)($row['amount']??0),$recordRows)),2,'.','');
            $record['win_amount']=number_format(array_sum(array_map(static fn(array $row): float=>(float)($row['win_amount']??0),$recordRows)),2,'.','');
            $statuses=array_map(static fn(array $row): string=>(string)($row['status']??'pending'),$recordRows);
            $record['status']=in_array('refunded',$statuses,true)?'refunded':(in_array('pending',$statuses,true)?'pending':((float)$record['win_amount']>0?'won':'unwon'));
        }
        $histories = [];
        foreach (array_keys($lotteries) as $name) {
            $lottery = Db::name('lotteries')->where('name', $name)->whereNull('deleted_at')->find();
            if (!$lottery) continue;
            $history = Db::name('lottery_histories')->where('lottery_id',(int)$lottery['id'])->where('code',(string)$record['issue_no'])->find();
            // A pending issue is intentionally inserted into lottery_histories before draw time.
            // Presence of the row alone therefore does not mean that the draw has happened.
            $histories[$name] = ['name'=>$name, 'opened'=>$this->historyHasDraw($history), 'numbers'=>$history['numbers'] ?? '', 'open_time'=>$history['open_time'] ?? null];
        }
        $record['lotteries'] = array_values($histories);
        $record['opened'] = (bool)array_reduce($histories, static fn(bool $carry, array $item): bool => $carry || $item['opened'], false);
        $record['draw_numbers'] = (string)(array_values(array_filter($histories, static fn(array $item): bool => $item['opened']))[0]['numbers'] ?? '');
        $record['win_status']=$record['opened']?$this->classifyWinStatus($details,(float)($record['win_amount']??0)):'pending';
        $expanded = [];
        foreach ($details as $detail) {
            $stop=$stops[(int)$detail['id']] ?? [];
            $numbers=preg_split('/\s+/', trim((string)($detail['number_text'] ?? ''))) ?: [];
            $numbers=array_values(array_filter($numbers, static fn(string $number): bool => preg_match('/^\d{3}$/',$number)===1));
            $detail['lottery']=(string)($stop['lottery'] ?? '');
            $numberCount=count($numbers) ?: 1;
            $lotteryId=(int)Db::name('lotteries')->where('name',$detail['lottery'])->whereNull('deleted_at')->value('id');
            $detailOdds=(float)($detail['odds'] ?? 0);
            if ($detailOdds <= 0 && $lotteryId > 0) $detailOdds=(new BetSettlement())->oddsFor($lotteryId,(string)($detail['source_text'] ?? ''),$numberCount);
            $detail['source_text']=$detail['source_text'] ?? '';
            $detail['play_type'] = (string)($stop['play_type'] ?? $detail['category'] ?? '未识别玩法');
            $detail['draw_numbers'] = (string)($histories[$detail['lottery']]['numbers'] ?? '');
            $detail['row_key']=(string)$detail['id'];
            $detail['number_index']=0;
            $detail['number_count']=$numberCount;
            $detail['hundreds']=''; $detail['tens']=''; $detail['units']='';
            $detail['amount']=number_format((float)($detail['amount']??0),2,'.','');
            $detail['odds']=number_format($detailOdds,4,'.','');
            $detail['win_amount']=number_format((float)($detail['win_amount']??0),2,'.','');
            if (!$record['opened']) $detail['result_status']='pending';
            else {
                $selectionCount=$this->detailSelectionCount($detail);
                $matchedCount=$this->detailMatchedCount($detail,$selectionCount);
                $detail['result_status']=$matchedCount===null?'won':($matchedCount<=0?'unwon':($matchedCount>=$selectionCount?'won':'partial'));
            }
            $expanded[]=$detail;
        }
        $total=count($expanded); $page=max(1,(int)$request->param('page',1)); $pageSize=min(100,max(10,(int)$request->param('page_size',30)));
        return $this->reply(['record'=>$record,'list'=>array_slice($expanded,($page-1)*$pageSize,$pageSize),'total'=>$total,'page'=>$page,'page_size'=>$pageSize]);
    }

    private function numberWon(string $number, string $drawNumbers, string $source): bool
    {
        return (new BetSettlement())->numberMatches($number,$drawNumbers,$source);
    }

    private function historyHasDraw(?array $history): bool
    {
        if (!is_array($history)) return false;
        if (array_key_exists('is_opened',$history)) return (int)$history['is_opened']===1;
        $digits = array_filter([
            trim((string)($history['one'] ?? '')),
            trim((string)($history['two'] ?? '')),
            trim((string)($history['three'] ?? '')),
        ], static fn(string $value): bool => $value !== '');
        if (count($digits) >= 3) return true;
        $numbers = preg_replace('/[^0-9]/', '', (string)($history['numbers'] ?? ''));
        return strlen((string)$numbers) >= 3;
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
        $storedText=trim((string)($detail['number_text']??''));
        $numbers=preg_split('/\s+/', $storedText) ?: [];
        $numbers=array_values(array_filter($numbers,static fn(string $number): bool => $number!==''));
        $numberIndex=(int)($data['number_index']??0);
        $number=trim((string)($data['number_text']??''));
        if ($number==='') throw new \InvalidArgumentException('号码/玩法内容不能为空');
        $isLegacyNumber=preg_match('/^\d{3}$/',$number)===1 && preg_match('/^\d{3}(?:\s+\d{3})*$/',$storedText)===1;
        if ($isLegacyNumber) {
            if (!isset($numbers[$numberIndex])) throw new \InvalidArgumentException('要修改的号码不存在');
            $numbers[$numberIndex]=$number; $numberText=implode(' ',$numbers);
        } else {
            $numberText=$number;
        }
        $amount=array_key_exists('amount',$data) ? (float)$data['amount'] : (float)$detail['amount'];
        if ($amount<0 || !is_finite($amount)) throw new \InvalidArgumentException('金额必须是非负数字');
        $playType=array_key_exists('play_type',$data)?trim((string)$data['play_type']):null;
        $sourceText=array_key_exists('source_text',$data)?trim((string)$data['source_text']):null;
        Db::transaction(function () use ($detail,$record,$numberText,$amount,$playType,$sourceText): void {
            $detailUpdate=['number_text'=>$numberText,'amount'=>number_format($amount,2,'.','')]; if($playType!==null&&$playType!=='')$detailUpdate['category']=$playType; if($sourceText!==null)$detailUpdate['source_text']=$sourceText;
            Db::name('bet_details')->where('id',$detail['id'])->update($detailUpdate);
            $stopUpdate=['number_text'=>$numberText,'original_amount'=>number_format($amount,2,'.',''),'actual_amount'=>number_format($amount,2,'.','')]; if($playType!==null&&$playType!=='')$stopUpdate['play_type']=$playType; Db::name('user_stop_drops')->where('bet_detail_id',$detail['id'])->update($stopUpdate);
            $total=(float)Db::name('bet_details')->where('bet_record_id',$record['id'])->sum('amount');
            $count=(int)Db::name('bet_details')->where('bet_record_id',$record['id'])->count();
            Db::name('bet_records')->where('id',$record['id'])->update(['amount'=>number_format($total,2,'.',''),'bet_count'=>$count]);
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
            $domainGroups=$this->domainGroups($data); $lotteryIds=$data['lottery_ids']??[]; $creditLimit=max(0,(float)($data['credit_limit']??0)); $maxProfitShareRate=$this->siteMaxShareRate($data['max_profit_share_rate']??100); $waterRate=$this->waterRate($data['water_rate']??$data['dark_water_rate']??0.085); unset($data['domain'], $data['agent_domain'], $data['user_domain'], $data['agent_domains'], $data['user_domains'], $data['lottery_ids'], $data['credit_limit'], $data['max_profit_share_rate'], $data['water_rate'], $data['dark_water_rate'], $data['bright_water_rate'], $data['code'], $data['parent_id'], $data['level'], $data['site_id'], $data['username'], $data['display_name'], $data['phone'], $data['password'], $data['manager_username'], $data['manager_password'], $data['manager_phone']);
            $data['settings']=json_encode(['credit_limit'=>$creditLimit,'max_profit_share_rate'=>$maxProfitShareRate,'water_rate'=>$waterRate],JSON_UNESCAPED_UNICODE);
            $data['agent_id'] = (int)($data['agent_id'] ?? 1);
            $operator=$this->scoreOperator($request);
            $siteId=Db::transaction(function () use ($data,$domainGroups,$lotteryIds,$creditLimit,$maxProfitShareRate,$operator): int {
                $this->assertDomainsAvailable($domainGroups);
                // ThinkPHP/MySQL may return AUTO_INCREMENT ids as numeric strings;
                // normalize once because the downstream helpers use strict int types.
                $siteId=(int)Db::name('sites')->insertGetId($data); $site=array_merge($data,['id'=>$siteId]);
                $this->replaceSiteDomains($site,$domainGroups);
                $this->replaceSiteLotteries($siteId,(int)$data['tenant_id'],$lotteryIds);
                $this->allocateSiteCredit($site,$creditLimit,$operator);
                $this->syncSiteShareCap($siteId,(int)$data['tenant_id'],$maxProfitShareRate);
                return $siteId;
            });
            return $this->reply(['id'=>$siteId], 'created');
        }
        if ($resource === 'admins' && (int)($data['site_id']??0)>0) $resource='site-admins';
        if ($resource === 'admins') { $data['user_type']='admin'; $data['display_name']=$data['display_name']??($data['name']??$data['username']??'管理员'); $password=PasswordPolicy::initial((string)($data['password']??''),(string)($data['username']??'')); $data['password']=password_hash($password,PASSWORD_DEFAULT); unset($data['name'],$data['code'],$data['site_id']); }
        if ($resource === 'site-admins') {
            unset($data['name'],$data['code'],$data['domain'],$data['parent_id']);
            $data['site_id']=(int)($data['site_id']??0); $site=Db::name('sites')->where('id',$data['site_id'])->whereNull('deleted_at')->find();
            if ($data['site_id']<1 || !$site) throw new \InvalidArgumentException('请选择有效站点');
            $data['tenant_id']=(int)$site['tenant_id'];
            $data['username']=trim((string)($data['username']??''));
            if ($data['username']==='') throw new \InvalidArgumentException('请输入管理员账号');
            if (Db::name('admins')->where('username',$data['username'])->whereNull('deleted_at')->find() || Db::name('site_admins')->where('username',$data['username'])->whereNull('deleted_at')->find() || Db::name('organization_accounts')->where('site_id',(int)$data['site_id'])->where('username',$data['username'])->whereNull('deleted_at')->find()) throw new \InvalidArgumentException('管理员账号已存在');
            $password=(string)($data['password']??''); PasswordPolicy::assertValid($password,$data['username']);
            $data['display_name']=trim((string)($data['display_name']??''))?:$data['username'];
            $data['password']=password_hash($password,PASSWORD_DEFAULT);
        }
        if ($resource === 'site-users') { unset($data['name'],$data['code'],$data['domain'],$data['parent_id'],$data['manager_username'],$data['manager_password'],$data['manager_phone'],$data['total_balance'],$data['available_balance']); $data['tenant_id']=(int)($data['tenant_id']??1); $data['site_id']=$scopedSiteId ?? (int)($data['site_id']??0); if ($data['site_id']<1 || !Db::name('sites')->where('id',$data['site_id'])->whereNull('deleted_at')->find()) throw new \InvalidArgumentException('请选择有效站点'); $data['username']=trim((string)($data['username']??'')); if ($data['username']==='') throw new \InvalidArgumentException('请输入用户账号'); $data['display_name']=$data['display_name']??$data['username']; $this->normalizeBalances($data); $password=PasswordPolicy::initial((string)($data['password']??''),$data['username']); $data['password']=password_hash($password,PASSWORD_DEFAULT); }
        if($resource==='site-users'){
            $initialBalance=(float)$data['balance'];$initialCredit=(float)$data['credit_balance'];$data['balance']='0.00';$data['credit_balance']='0.00';$operator=$this->scoreOperator($request);
            $id=(int)Db::transaction(function()use($data,$initialBalance,$initialCredit,$operator):int{$id=(int)Db::name('site_users')->insertGetId($data);$user=Db::name('site_users')->where('id',$id)->find();ScoreTransfer::setUserBalances($user,$initialBalance,$initialCredit,$operator);return$id;});
        }else $id = Db::name($this->table($resource))->insertGetId($data);
        return $this->reply(['id'=>$id], 'created');
    }

    public function update(Request $request, string $resource, int $id): \think\response\Json
    {
        $resource = $this->normalize($resource);
        if ($resource === 'bet-records') throw new \RuntimeException('下单记录为只读数据');
        $data=$request->put(); unset($data['id'],$data['created_at']); $data['updated_at']=date('Y-m-d H:i:s');
        if($resource==='admins'&&((string)($data['account_table']??'')==='site_admins'))$resource='site-admins';
        unset($data['account_table'],$data['scope_label'],$data['site_name']);
        $scopedSiteId=$this->scopedSiteId($request);
        $this->authorizeResource($resource,$scopedSiteId);
        if ($resource === 'agent-center') {
            $domainGroups=$this->domainGroups($data); $lotteryIds=$data['lottery_ids']??[];
            $siteBefore=Db::name('sites')->where('id',$id)->value('settings'); $siteSettings=is_string($siteBefore)?json_decode($siteBefore,true):(is_array($siteBefore)?$siteBefore:[]);
            $creditLimit=max(0,(float)($data['credit_limit']??($siteSettings['credit_limit']??0))); $maxProfitShareRate=$this->siteMaxShareRate($data['max_profit_share_rate']??($siteSettings['max_profit_share_rate']??100)); $waterRate=$this->waterRate($data['water_rate']??($siteSettings['water_rate']??$siteSettings['dark_water_rate']??0.085)); unset($data['credit_limit'],$data['max_profit_share_rate'],$data['water_rate'],$data['dark_water_rate'],$data['bright_water_rate']);
            // Score is managed on each root director. Keep this legacy field as
            // a read-only aggregate so editing site metadata can never move
            // the first director's balance or mix director pools together.
            $siteSettings=['credit_limit'=>$creditLimit,'max_profit_share_rate'=>$maxProfitShareRate,'water_rate'=>$waterRate]; $data['settings']=json_encode($siteSettings,JSON_UNESCAPED_UNICODE);
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
            $duplicateOrganization=Db::name('organization_accounts')->where('site_id',(int)$current['site_id'])->where('username',$username)->whereNull('deleted_at')->find();
            if ($duplicatePlatform || $duplicateSite || ($username!==(string)$current['username'] && $duplicateOrganization)) throw new \InvalidArgumentException('管理员账号已存在');
            $data['username']=$username; $data['display_name']=trim((string)($data['display_name']??''))?:$username;
        }
        if ($resource === 'site-users' && $scopedSiteId !== null) { unset($data['site_id']); $update->where('site_id',$scopedSiteId); }
        if ($resource === 'site-users') { unset($data['total_balance'],$data['available_balance'],$data['organization_id']); $current=Db::name('site_users')->where('id',$id)->find(); if (!$current) throw new \InvalidArgumentException('用户不存在'); $this->normalizeBalances($data,$current); }
        if($resource==='site-users'&&isset($current)){
            $operator=$this->scoreOperator($request);
            Db::transaction(function()use($update,$data,$current,$operator):void{
                ScoreTransfer::setUserBalances($current,(float)$data['balance'],(float)$data['credit_balance'],$operator);
                unset($data['balance'],$data['credit_balance'],$data['used_balance']);
                $update->update($data);
            });
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
        if($resource==='admins'&&((string)$request->param('account_table','')==='site_admins'))$resource='site-admins';
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
