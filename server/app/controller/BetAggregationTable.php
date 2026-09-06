<?php
declare(strict_types=1);

namespace app\controller;

use app\service\BetSummaryTable;
use think\Request;
use think\facade\Cache;
use think\facade\Db;
use think\response\Json;

/** Read-only summary endpoints; never re-recognize, reprice or settle a bet. */
final class BetAggregationTable
{
    private function reply(mixed $data = null, string $message = '', int $code = 0): Json
    {
        return json(['code' => $code, 'message' => $message, 'data' => $data, 'request_id' => bin2hex(random_bytes(8))]);
    }

    private function scopedSite(Request $request): ?int
    {
        $token = trim(str_ireplace('Bearer ', '', (string)$request->header('authorization')));
        $session = $token !== '' ? Cache::get('token:'.$token) : null;
        if (!is_array($session) || ($session['scope'] ?? '') !== 'admin') {
            throw new \RuntimeException('未登录或登录已过期');
        }
        if (($session['admin_role'] ?? '') === 'platform') return null;
        $site = (int)($session['site_id'] ?? 0);
        if ($site < 1) throw new \RuntimeException('当前管理员未绑定站点');
        return $site;
    }

    private function rows(Request $request): \Generator
    {
        $site = $this->scopedSite($request);
        $query = Db::name('bet_details')->alias('d')
            ->join('bet_records r', 'r.id=d.bet_record_id')
            ->leftJoin('user_stop_drops s', 's.bet_detail_id=d.id')
            ->leftJoin('site_users u', 'u.id=r.user_id AND u.site_id=r.site_id')
            ->field('d.id detail_id,d.bet_record_id,r.site_id,r.user_id,r.issue_no,r.status record_status,d.number_text,d.source_text,d.amount,d.odds,d.win_amount,d.board_code,s.lottery,s.play_type');
        if ($site !== null) $query->where('r.site_id', $site);
        elseif ((int)$request->param('site_id', 0) > 0) $query->where('r.site_id', (int)$request->param('site_id'));
        foreach (['lottery' => 's.lottery', 'issue_no' => 'r.issue_no', 'board_code' => 'd.board_code'] as $param => $field) {
            $value = trim((string)$request->param($param, ''));
            if ($value !== '') $query->where($field, $value);
        }
        $member = trim((string)$request->param('member', ''));
        if ($member !== '') $query->where(function ($q) use ($member) {
            $q->whereLike('u.username', '%'.$member.'%')->whereOr('u.display_name', 'like', '%'.$member.'%');
        });
        $play = trim((string)$request->param('play', ''));
        if ($play !== '') $query->where(function ($q) use ($play) {
            $q->whereLike('s.play_type', '%'.$play.'%')->whereOr('d.source_text', 'like', '%'.$play.'%');
        });
        $status = (string)$request->param('draw_status', 'pending');
        if (!in_array($status, ['pending', 'opened', 'all'], true)) throw new \InvalidArgumentException('开奖状态无效');
        if ($status === 'pending') $query->where('r.status', 'pending');
        elseif ($status === 'opened') $query->whereIn('r.status', ['won', 'unwon']);
        elseif ((int)$request->param('include_refunded', 0) !== 1) $query->where('r.status', '<>', 'refunded');
        foreach (['from' => ['>=', ' 00:00:00'], 'to' => ['<=', ' 23:59:59']] as $param => [$op, $time]) {
            $date = trim((string)$request->param($param, ''));
            if ($date === '') continue;
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new \InvalidArgumentException('日期格式必须为有效的 YYYY-MM-DD');
            $query->where('r.placed_at', $op, $date.$time);
        }
        if ($request->param('from', '') !== '' && $request->param('to', '') !== '' && $request->param('from') > $request->param('to')) throw new \InvalidArgumentException('开始日期不能晚于结束日期');
        $lastId = 0;
        do {
            $page = (clone $query)->where('d.id', '>', $lastId)->order('d.id')->limit(1000)->select()->toArray();
            foreach ($page as $row) {
                $lastId = (int)$row['detail_id'];
                yield $row;
            }
        } while (count($page) === 1000);
    }

    private function catalog(string $lottery): array
    {
        $id = str_contains($lottery, '排') ? 2 : 1;
        $rows = Db::name('lottery_odds')->where('lottery_id', $id)->field('category,name,odds')->select()->toArray();
        foreach (Db::name('lottery_odds_categories')->where('lottery_id', $id)->where('is_playable', 1)->field('name,odds')->select()->toArray() as $category) {
            $rows[] = ['category' => $category['name'], 'name' => $category['name'], 'odds' => $category['odds']];
        }
        return $rows;
    }

    public function index(Request $request): Json
    {
        try {
            $service = new BetSummaryTable();
            $summary = $service->aggregate($this->rows($request), true);
            $unmapped = 0;
            foreach ($summary['list'] as &$group) {
                $unmapped += $group['unresolved_count'];
                $group['numbers'] = array_values(array_map(static fn($c) => [
                    'key' => $c['key'], 'title' => $c['title'], 'odds' => $c['odds'], 'amount' => BetSummaryTable::money($c['amount_cents']),
                ], $group['columns']));
                usort($group['numbers'], static fn($a, $b) => (float)$b['amount'] <=> (float)$a['amount']);
                unset($group['columns']);
            }
            unset($group);
            $fields = ['amount' => 'amount_cents', 'actual_win_amount' => 'win_amount_cents', 'max_cell_amount' => 'max_cell_cents', 'max_payout' => 'max_payout_cents', 'order_count' => 'order_count', 'member_count' => 'member_count'];
            $field = $fields[(string)$request->param('sort_field', 'amount')] ?? 'amount_cents';
            $direction = $request->param('sort_order') === 'asc' ? 1 : -1;
            usort($summary['list'], static fn($a, $b) => $direction * ($a[$field] <=> $b[$field]) ?: strcmp($a['key'], $b['key']));
            $total = count($summary['list']);
            $size = min(100, max(1, (int)$request->param('page_size', 20)));
            $page = max(1, (int)$request->param('page', 1));
            return $this->reply(['list' => array_slice($summary['list'], ($page - 1) * $size, $size), 'total' => $total, 'source_item_count' => $summary['source_count'], 'unmapped_item_count' => $unmapped]);
        } catch (\Throwable $e) {
            return $this->reply(null, $e->getMessage(), 422);
        }
    }

    public function details(Request $request): Json
    {
        try {
            $family = (string)$request->param('family', '');
            $groupKey = (string)$request->param('group_key', '');
            if (!isset(BetSummaryTable::FAMILIES[$family]) || !preg_match('/^[a-f0-9]{64}$/', $groupKey)) throw new \InvalidArgumentException('请选择有效的汇总行');
            $service = new BetSummaryTable();
            $summary = $service->aggregate($this->rows($request), true, $family);
            $groups = array_values(array_filter($summary['list'], static fn($g) => $g['key'] === $groupKey));
            $group = $groups[0] ?? null;
            $columns = $group ? $service->columns(
                $group, $this->catalog($group['lottery']), (string)$request->param('sort', 'amount'),
                (string)$request->param('direction', 'desc'), max(1, (int)$request->param('page', 1)),
                (string)$request->param('column_key', ''), (int)$request->param('only_bet', 0) === 1,
                trim((string)$request->param('search', ''))
            ) : [];
            return $this->reply(['family' => $family, 'columns' => $columns, 'source_item_count' => $summary['source_count']]);
        } catch (\Throwable $e) {
            return $this->reply(null, $e->getMessage(), 422);
        }
    }
}
