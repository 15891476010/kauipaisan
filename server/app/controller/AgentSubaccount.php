<?php
declare(strict_types=1);

namespace app\controller;

use app\service\AccountPresence;
use app\service\OrganizationHierarchy;
use app\service\PasswordPolicy;
use think\Request;
use think\facade\Cache;
use think\facade\Db;

final class AgentSubaccount
{
    public const PERMISSIONS = [
        'overview' => '总货概览', 'order_details' => '总货明细', 'bet_details' => '投注明细', 'winning_details' => '中奖明细', 'refunds' => '查看退码',
        'reports' => '综合报表', 'monthly_reports' => '月报表', 'results' => '开奖号码', 'contribution' => '贡献度',
        'daily_ledger' => '日分类账', 'monthly_ledger' => '月分类账', 'daily_path' => '日路径账', 'monthly_path' => '月路径账',
        'interception_details' => '拦货明细', 'interception_winning' => '拦货中奖', 'interception_plate' => '拦货盘', 'subordinates' => '下级管理',
    ];

    private function reply(mixed $data = null, string $message = 'ok', int $code = 0): \think\response\Json
    {
        return json(['code' => $code, 'message' => $message, 'data' => $data, 'request_id' => bin2hex(random_bytes(8))]);
    }

    private function session(Request $request): array
    {
        $token = trim(str_ireplace('Bearer ', '', (string)$request->header('authorization')));
        $session = $token !== '' ? Cache::get('token:'.$token) : null;
        if (!is_array($session) || ($session['scope'] ?? '') !== 'agent') throw new \RuntimeException('未登录或登录已过期');
        if ((int)($session['site_id'] ?? 0) < 1) throw new \RuntimeException('当前代理未绑定站点');
        if (!empty($session['is_subaccount'])) throw new \RuntimeException('子账号无权管理子账号');
        return $session;
    }

    private function scope(mixed $query,array $session): mixed
    {
        $node=OrganizationHierarchy::nodeForSession($session);
        if(!$node)return $query->whereRaw('1=0');
        return $query->where(function($nested)use($node):void{
            $nested->where('organization_id',(int)$node['id']);
            if((int)$node['parent_id']===0)$nested->whereOrRaw('organization_id IS NULL');
        });
    }

    public function options(Request $request): \think\response\Json
    {
        $session = $this->session($request); $siteId = (int)$session['site_id'];
        $lotteries = Db::name('lotteries')->alias('l')->join('site_lotteries sl', 'sl.lottery_id=l.id')->where('sl.site_id', $siteId)->where('l.status', 1)->whereNull('l.deleted_at')->field('l.id,l.name,l.code')->order('l.sort asc')->order('l.id asc')->select()->toArray();
        $configuredLimit = (int)Db::name('settings')->where('site_id', $siteId)->where('key', 'draw_history_limit')->value('value');
        $drawHistoryLimit = $configuredLimit > 0 ? min(200, $configuredLimit) : 80;
        $issues = Db::name('lottery_histories')->alias('h')->join('site_lotteries sl', 'sl.lottery_id=h.lottery_id')->where('sl.site_id', $siteId)->where('h.draw_day', '>=', date('Y-m-01'))->where('h.draw_day', '<=', date('Y-m-t'))->field('h.code AS issue_no,h.draw_day AS date')->order('h.draw_day desc')->order('h.code desc')->limit($drawHistoryLimit)->select()->toArray();
        $seen = []; $issueList = []; foreach ($issues as $row) if (!isset($seen[(string)$row['issue_no']])) { $seen[(string)$row['issue_no']] = true; $issueList[] = $row; }
        $allowed=array_map('strval',(array)($session['permissions']??[]));
        $permissionList = []; foreach (self::PERMISSIONS as $key => $label) if(in_array('*',$allowed,true)||in_array($key,$allowed,true))$permissionList[] = ['key' => $key, 'label' => $label];
        return $this->reply(['permissions' => $permissionList, 'lotteries' => $lotteries, 'issues' => $issueList]);
    }

    public function index(Request $request): \think\response\Json
    {
        $session = $this->session($request); $query = Db::name('agent_subaccounts')->where('site_id', (int)$session['site_id'])->whereNull('deleted_at');$this->scope($query,$session);
        $username = trim((string)$request->param('username', '')); if ($username !== '') $query->whereLike('username', '%'.$username.'%');
        $displayName = trim((string)$request->param('display_name', '')); if ($displayName !== '') $query->whereLike('display_name', '%'.$displayName.'%');
        $status = $request->param('status'); if ($status !== null && $status !== '') $query->where('status', (int)$status === 1 ? 1 : 0);
        $page = max(1, (int)$request->param('page', 1)); $size = min(100, max(1, (int)$request->param('page_size', 10))); $total = (clone $query)->count();
        $rows = $query->field('id,username,display_name,status,last_login_at,last_login_ip,last_login_location,created_at')->order('id desc')->page($page, $size)->select()->toArray();
        AccountPresence::append($rows,'agent_subaccount');
        return $this->reply(['list' => $rows, 'total' => $total, 'page' => $page, 'page_size' => $size]);
    }

    public function detail(Request $request, int $id): \think\response\Json
    {
        $session = $this->session($request); $query=Db::name('agent_subaccounts')->where('id', $id)->where('site_id', (int)$session['site_id'])->whereNull('deleted_at');$this->scope($query,$session);$row=$query->find();
        if (!$row) throw new \InvalidArgumentException('子账号不存在'); return $this->reply($this->format($row));
    }

    public function create(Request $request): \think\response\Json
    {
        $session = $this->session($request); $data = $request->post(); $values = $this->validate($data, (int)$session['site_id'], 0, false);$node=OrganizationHierarchy::nodeForSession($session);if(!$node)throw new \RuntimeException('当前账号尚未绑定组织');
        $values['permissions']=json_encode($this->allowedPermissions(json_decode((string)$values['permissions'],true)?:[],$session),JSON_UNESCAPED_UNICODE);
        $values += ['tenant_id' => (int)($session['tenant_id'] ?? 1), 'site_id' => (int)$session['site_id'], 'organization_id'=>(int)$node['id'], 'agent_id' => (int)($session['agent_id'] ?? 0), 'created_at' => date('Y-m-d H:i:s')];
        $password=PasswordPolicy::initial((string)($data['password']??''),(string)$values['username']);
        $values['updated_at'] = $values['created_at']; $values['password'] = password_hash($password, PASSWORD_DEFAULT); $values['must_change_password']=1;
        $id = (int)Db::name('agent_subaccounts')->insertGetId($values); return $this->reply(['id' => $id,'username'=>$values['username'],'initial_password'=>$password,'must_change_password'=>1], '子账号创建成功');
    }

    public function update(Request $request, int $id): \think\response\Json
    {
        $session = $this->session($request); $siteId = (int)$session['site_id']; $query=Db::name('agent_subaccounts')->where('id', $id)->where('site_id', $siteId)->whereNull('deleted_at');$this->scope($query,$session);$existing=$query->find();
        if (!$existing) throw new \InvalidArgumentException('子账号不存在'); $data = $request->put(); $values = $this->validate($data, $siteId, $id, false);$values['permissions']=json_encode($this->allowedPermissions(json_decode((string)$values['permissions'],true)?:[],$session),JSON_UNESCAPED_UNICODE); $values['updated_at'] = date('Y-m-d H:i:s');
        if (!empty($data['password'])) { PasswordPolicy::assertValid((string)$data['password'],(string)($data['username'] ?? $existing['username']),(string)$existing['password']); $values['password'] = password_hash((string)$data['password'], PASSWORD_DEFAULT); }
        $updateQuery=Db::name('agent_subaccounts')->where('id',$id)->where('site_id',$siteId);$this->scope($updateQuery,$session);$updateQuery->update($values); return $this->reply(null, '子账号修改成功');
    }

    public function delete(Request $request, int $id): \think\response\Json
    {
        $session = $this->session($request); $query=Db::name('agent_subaccounts')->where('id', $id)->where('site_id', (int)$session['site_id'])->whereNull('deleted_at');$this->scope($query,$session);$affected=$query->delete();
        if (!$affected) throw new \InvalidArgumentException('子账号不存在'); return $this->reply(null, '子账号已删除');
    }

    public function batchDelete(Request $request): \think\response\Json
    {
        $session = $this->session($request); $ids = array_values(array_unique(array_filter(array_map('intval', (array)$request->post('ids', []))))); if ($ids === []) throw new \InvalidArgumentException('请选择需要删除的子账号');
        $query=Db::name('agent_subaccounts')->where('site_id', (int)$session['site_id'])->whereIn('id', $ids)->whereNull('deleted_at');$this->scope($query,$session);$count=$query->delete();
        return $this->reply(['count' => $count], '选中的子账号已删除');
    }

    private function validate(array $data, int $siteId, int $exceptId = 0, bool $passwordRequired = true): array
    {
        $username = trim((string)($data['username'] ?? '')); $displayName = trim((string)($data['display_name'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) throw new \InvalidArgumentException('账号名只能使用3到30位字母、数字或下划线');
        if ($displayName === '') $displayName = $username; if (mb_strlen($displayName) > 40) throw new \InvalidArgumentException('代号不能超过40个字符');
        $duplicate = Db::name('agent_subaccounts')->where('site_id', $siteId)->where('username', $username)->whereNull('deleted_at'); if ($exceptId > 0) $duplicate->where('id', '<>', $exceptId); if ($duplicate->find()) throw new \InvalidArgumentException('该账号名已存在');
        $agentId=(int)Db::name('sites')->where('id',$siteId)->value('agent_id');
        if(Db::name('agent_admins')->where('agent_id',$agentId)->where('username',$username)->whereNull('deleted_at')->find()||Db::name('site_admins')->where('site_id',$siteId)->where('username',$username)->whereNull('deleted_at')->find()||Db::name('sites')->where('id',$siteId)->where('manager_username',$username)->whereNull('deleted_at')->find()) throw new \InvalidArgumentException('账号名与现有主账号重复');
        if ($passwordRequired) PasswordPolicy::assertValid((string)($data['password'] ?? ''),$username);
        $permissions = array_values(array_intersect(array_keys(self::PERMISSIONS), array_map('strval', (array)($data['permissions'] ?? []))));
        $validLotteryIds = array_map('intval', Db::name('site_lotteries')->where('site_id', $siteId)->column('lottery_id')); $lotteryPermissions = array_values(array_intersect($validLotteryIds, array_map('intval', (array)($data['lottery_permissions'] ?? []))));
        return ['username' => $username, 'display_name' => $displayName, 'permissions' => json_encode($permissions, JSON_UNESCAPED_UNICODE), 'lottery_permissions' => json_encode($lotteryPermissions), 'report_limit_enabled' => !empty($data['report_limit_enabled']) ? 1 : 0, 'report_from_issue' => trim((string)($data['report_from_issue'] ?? '')) ?: null, 'report_to_issue' => trim((string)($data['report_to_issue'] ?? '')) ?: null, 'status' => (int)($data['status'] ?? 1) === 0 ? 0 : 1];
    }

    private function format(array $row): array
    {
        foreach (['permissions', 'lottery_permissions'] as $key) { $decoded = json_decode((string)($row[$key] ?? ''), true); $row[$key] = is_array($decoded) ? $decoded : []; }
        unset($row['password'], $row['deleted_at']); return $row;
    }

    private function allowedPermissions(array $requested,array $session): array
    {
        $allowed=array_map('strval',(array)($session['permissions']??[]));
        if(in_array('*',$allowed,true))return array_values(array_intersect(array_keys(self::PERMISSIONS),array_map('strval',$requested)));
        return array_values(array_intersect(array_map('strval',$requested),$allowed));
    }
}
