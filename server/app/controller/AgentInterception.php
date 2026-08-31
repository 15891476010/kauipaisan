<?php
declare(strict_types=1);

namespace app\controller;

use app\service\OrganizationHierarchy;
use think\Request;
use think\facade\Cache;
use think\facade\Db;

final class AgentInterception
{
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
        return $session;
    }

    private function lottery(array $session, string $name): array
    {
        $row = Db::name('lotteries')->alias('l')->join('site_lotteries sl', 'sl.lottery_id=l.id')
            ->where('sl.site_id', (int)$session['site_id'])->where('l.name', $name)
            ->where('l.status', 1)->whereNull('l.deleted_at')->field('l.id,l.name,l.code')->find();
        if (!$row) throw new \InvalidArgumentException('当前彩种不可用');
        return is_object($row) ? $row->toArray() : $row;
    }

    public function issues(Request $request): \think\response\Json
    {
        $session = $this->session($request); $lottery = $this->lottery($session, trim((string)$request->param('lottery', '')));
        $configuredLimit = (int)Db::name('settings')->where('site_id', (int)$session['site_id'])->where('key', 'draw_history_limit')->value('value');
        $drawHistoryLimit = $configuredLimit > 0 ? min(200, $configuredLimit) : 80;
        $rows = Db::name('lottery_histories')->where('lottery_id', (int)$lottery['id'])
            ->where('draw_day', '>=', date('Y-m-01'))->where('draw_day', '<=', date('Y-m-t'))
            ->field('code AS issue_no,draw_day AS date')->order('draw_day desc')->order('code desc')->limit($drawHistoryLimit)->select()->toArray();
        return $this->reply(['list' => $rows]);
    }

    public function categories(Request $request): \think\response\Json
    {
        $session = $this->session($request); $lottery = $this->lottery($session, trim((string)$request->param('lottery', '')));
        $rows = Db::name('lottery_odds')->where('lottery_id', (int)$lottery['id'])->where('status', 1)->whereNull('deleted_at')
            ->field('id,category,name,odds,sort')->order('sort asc')->order('id asc')->select()->toArray();
        foreach (Db::name('lottery_odds_categories')->where('lottery_id', (int)$lottery['id'])->where('is_playable', 1)->where('status', 1)->whereNull('deleted_at')->field('id,name,odds,sort')->select()->toArray() as $row) {
            $rows[] = ['id' => 1000000000 + (int)$row['id'], 'category' => (string)$row['name'], 'name' => (string)$row['name'], 'odds' => $row['odds'], 'sort' => $row['sort']];
        }
        usort($rows, static fn(array $a, array $b): int => ((int)$a['sort'] <=> (int)$b['sort']) ?: ((int)$a['id'] <=> (int)$b['id']));
        return $this->reply(['list' => $rows]);
    }

    public function index(Request $request): \think\response\Json
    {
        $session = $this->session($request); $lottery = $this->lottery($session, trim((string)$request->param('lottery', '')));
        $view = trim((string)$request->param('view', 'details'));
        if (!in_array($view, ['details', 'winning'], true)) throw new \InvalidArgumentException('拦货查询类型不正确');
        $fromIssue = trim((string)$request->param('from_issue', '')); $toIssue = trim((string)$request->param('to_issue', $fromIssue));
        $issues = $this->issueRange((int)$lottery['id'], $fromIssue, $toIssue);
        if ($issues === []) return $this->reply(['list' => [], 'total' => 0, 'summary' => $this->summary([])]);

        $query = Db::name('agent_interceptions')->alias('i')
            ->join('bet_details d', 'd.id=i.bet_detail_id')->join('bet_records r', 'r.id=i.bet_record_id')
            ->join('site_users u', 'u.id=i.user_id')->leftJoin('lottery_odds o', 'o.id=i.lottery_odds_id')
            ->where('i.site_id', (int)$session['site_id'])->where('i.lottery_id', (int)$lottery['id'])
            ->whereIn('i.issue_no', $issues)->whereNull('i.released_at')->where('r.status', '<>', 'refunded');
        OrganizationHierarchy::applyUserScope($query,$session,'i.user_id');
        if ($view === 'winning') $query->where('d.win_amount', '>', 0);
        $account = trim((string)$request->param('account', '')); if ($account !== '') $query->whereLike('u.username', '%'.$account.'%');
        $number = trim((string)$request->param('number', '')); if ($number !== '') $query->whereLike('i.number_key', '%'.$number.'%');
        $oddsId = (int)$request->param('odds_id', 0); if ($oddsId > 0) $query->where('i.lottery_odds_id', $oddsId);
        if ((int)$request->param('group_only', 0) === 1) $query->where(function ($nested): void { $nested->whereLike('o.category', '%组%')->whereOrLike('d.category', '%组%'); });
        $metric = (string)$request->param('metric', 'odds'); $min = $request->param('min'); $max = $request->param('max');
        $field = $metric === 'amount' ? 'i.intercepted_amount' : 'd.odds';
        if ($min !== null && $min !== '' && is_numeric($min)) $query->where($field, '>=', (float)$min);
        if ($max !== null && $max !== '' && is_numeric($max)) $query->where($field, '<=', (float)$max);
        $direction = strtolower((string)$request->param('order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $rows = $query->field('i.id,i.lottery_odds_id,i.bet_record_id,i.issue_no,i.number_key,i.bet_amount,i.share_rate,i.requested_amount,i.intercepted_amount,i.allocation_status,i.created_at,u.username,d.amount AS detail_amount,d.odds,d.rebate,d.win_amount,o.category,o.name AS odds_name')
            ->order('i.created_at '.$direction)->order('i.id '.$direction)->select()->toArray();
        $directIds = []; foreach ($rows as $row) if ((int)$row['lottery_odds_id'] >= 1000000000) $directIds[] = (int)$row['lottery_odds_id'] - 1000000000;
        $directNames = $directIds === [] ? [] : Db::name('lottery_odds_categories')->whereIn('id', array_values(array_unique($directIds)))->column('name', 'id');
        foreach ($rows as &$row) if ((int)$row['lottery_odds_id'] >= 1000000000) $row['odds_name'] = (string)($directNames[(int)$row['lottery_odds_id'] - 1000000000] ?? $row['odds_name']); unset($row);
        [$darkRate,$brightRate]=$this->waterRates((int)$session['site_id']);
        foreach ($rows as &$row) $row = $this->detailRow($row,$darkRate,$brightRate); unset($row);
        return $this->reply(['list' => $rows, 'total' => count($rows), 'summary' => $this->summary($rows)]);
    }

    public function plate(Request $request): \think\response\Json
    {
        $session = $this->session($request); $siteId = (int)$session['site_id'];
        $lottery = $this->lottery($session, trim((string)$request->param('lottery', ''))); $lotteryId = (int)$lottery['id'];
        $issue = trim((string)$request->param('issue_no', ''));
        if ($issue === '') $issue = (string)Db::name('lottery_histories')->where('lottery_id', $lotteryId)->where('draw_day', '<=', date('Y-m-d'))->order('draw_day desc')->order('code desc')->value('code');
        $node=OrganizationHierarchy::nodeForSession($session);
        if(!$node||(string)$node['level']!=='agent')throw new \InvalidArgumentException('拦货盘仅显示当前直接代理的独立容量，请进入直属代理账号查看');
        $configuration=(new \app\service\InterceptionAllocator())->agentConfiguration((int)$session['tenant_id'],$siteId,(int)$node['id'],$lotteryId);
        $amounts=$configuration['amounts'];
        $usageRows=Db::name('interception_capacity_usage')->where('tenant_id',(int)$session['tenant_id'])->where('scope_type','organization')->where('scope_id',(int)$node['id'])
            ->where('lottery_id',$lotteryId)->where('issue_no',$issue)->where('used_amount','>',0)->where('number_key','__PLAY_TOTAL__')->field('lottery_odds_id,used_amount')->order('lottery_odds_id asc')->select()->toArray();
        $usage = []; foreach ($usageRows as $row) { $limit=max(0,(float)($amounts[(string)(int)$row['lottery_odds_id']]??0));$used=(float)$row['used_amount'];$usage[(int)$row['lottery_odds_id']] = ['used' => $this->number($used),'remaining'=>$this->number(max(0,$limit-$used))]; }
        $odds = Db::name('lottery_odds')->where('lottery_id', $lotteryId)->where('status', 1)->whereNull('deleted_at')->field('id,category,name,odds,sort')->order('sort asc')->order('id asc')->select()->toArray();
        foreach (Db::name('lottery_odds_categories')->where('lottery_id', $lotteryId)->where('is_playable', 1)->where('status', 1)->whereNull('deleted_at')->field('id,name,odds,sort')->select()->toArray() as $row) {
            $odds[] = ['id' => 1000000000 + (int)$row['id'], 'category' => (string)$row['name'], 'name' => (string)$row['name'], 'odds' => $row['odds'], 'sort' => $row['sort']];
        }
        usort($odds, static fn(array $a, array $b): int => ((int)$a['sort'] <=> (int)$b['sort']) ?: ((int)$a['id'] <=> (int)$b['id']));
        $groups = [];
        foreach ($odds as $row) {
            $id = (int)$row['id']; $limit = max(0, (float)($amounts[(string)$id] ?? 0)); $used = (float)($usage[$id]['used'] ?? 0); $remaining=max(0,$limit-$used);
            $category = (string)($row['category'] ?: '其他');
            $groups[$category][] = ['odds_id' => $id, 'name' => (string)$row['name'], 'odds' => $this->number((float)$row['odds']), 'limit' => $this->number($limit), 'used' => $this->number($used), 'remaining' => $this->number($remaining), 'numbers' => []];
        }
        $list = []; foreach ($groups as $category => $items) $list[] = ['category' => $category, 'items' => $items];
        return $this->reply(['issue_no' => $issue, 'capacity_scope'=>'organization','organization_id'=>(int)$node['id'],'configuration_source'=>$configuration['source'],'groups' => $list]);
    }

    private function issueRange(int $lotteryId, string $from, string $to): array
    {
        if ($from === '' && $to === '') { $latest = (string)Db::name('lottery_histories')->where('lottery_id', $lotteryId)->where('draw_day', '<=', date('Y-m-d'))->order('draw_day desc')->order('code desc')->value('code'); return $latest !== '' ? [$latest] : []; }
        if ($from === '' || $to === '') $from = $to = $from !== '' ? $from : $to;
        $fromDate = Db::name('lottery_histories')->where('lottery_id', $lotteryId)->where('code', $from)->value('draw_day');
        $toDate = Db::name('lottery_histories')->where('lottery_id', $lotteryId)->where('code', $to)->value('draw_day');
        if (!$fromDate || !$toDate) return []; if ($fromDate > $toDate) [$fromDate, $toDate] = [$toDate, $fromDate];
        return array_values(array_unique(array_map('strval', Db::name('lottery_histories')->where('lottery_id', $lotteryId)->where('draw_day', '>=', $fromDate)->where('draw_day', '<=', $toDate)->column('code'))));
    }

    private function detailRow(array $row,float $darkRate=0.085,float $brightRate=0.012): array
    {
        $detailAmount = max(0, (float)$row['detail_amount']); $intercepted = max(0, (float)$row['intercepted_amount']);
        $ratio = $detailAmount > 0 ? min(1, $intercepted / $detailAmount) : 0; $rebate = (float)$row['rebate'] * $ratio; $winning = (float)$row['win_amount'] * $ratio;
        $memberProfit=(float)$row['win_amount']+(float)$row['rebate']-$detailAmount; $occupation=$memberProfit*max(0,min(100,(float)$row['share_rate']))/100; $dark=$detailAmount*max(0,min(1,$darkRate)); $bright=$occupation*max(0,min(1,$brightRate));
        return ['id' => (int)$row['id'], 'order_no' => (string)$row['bet_record_id'], 'issue_no' => (string)$row['issue_no'], 'member' => (string)$row['username'], 'placed_at' => (string)$row['created_at'], 'number' => (string)$row['number_key'], 'category' => (string)($row['odds_name'] ?: $row['category'] ?: '未分类'), 'bet_amount' => $this->number((float)$row['bet_amount']), 'share_rate' => $this->number((float)$row['share_rate']).'%', 'intercepted_amount' => $this->number($intercepted), 'odds' => $this->number((float)$row['odds']), 'rebate' => $this->number($rebate), 'win_amount' => $this->number($winning), 'profit' => $this->number($occupation+$dark+$bright), 'dark_water' => $this->number($dark), 'bright_water' => $this->number($bright), 'occupation_amount' => $this->number($occupation), 'source' => '快录', 'device' => '网', 'status' => (string)$row['allocation_status']];
    }

    private function waterRates(int $siteId): array
    {
        $settings=Db::name('sites')->where('id',$siteId)->value('settings');
        $settings=is_string($settings)?json_decode($settings,true):(is_array($settings)?$settings:[]);
        return [max(0,min(1,(float)($settings['dark_water_rate']??0.085))),max(0,min(1,(float)($settings['bright_water_rate']??0.012)))];
    }

    private function summary(array $rows): array
    {
        $total = ['bet_amount' => 0.0, 'intercepted_amount' => 0.0, 'rebate' => 0.0, 'win_amount' => 0.0, 'profit' => 0.0];
        foreach ($rows as $row) foreach ($total as $key => $value) $total[$key] += (float)($row[$key] ?? 0);
        foreach ($total as $key => $value) $total[$key] = $this->number($value); return $total;
    }

    private function number(float $value): string { return rtrim(rtrim(number_format(abs($value) < 0.005 ? 0 : $value, 2, '.', ''), '0'), '.') ?: '0'; }
}
