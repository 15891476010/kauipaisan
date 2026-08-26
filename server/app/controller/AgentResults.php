<?php
declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\facade\Cache;
use think\facade\Db;

final class AgentResults
{
    public function index(Request $r): \think\response\Json
    {
        $token = trim(str_ireplace('Bearer ', '', (string)$r->header('authorization')));
        $session = $token !== '' ? Cache::get('token:' . $token) : null;
        if (!is_array($session) || ($session['scope'] ?? '') !== 'agent') {
            return json(['code' => 401, 'message' => '未登录或登录已过期', 'data' => null]);
        }

        $name = trim((string)$r->param('lottery', ''));
        $lotteryId = (int)Db::name('lotteries')->alias('l')
            ->join('site_lotteries sl', 'sl.lottery_id=l.id')
            ->where('sl.site_id', (int)$session['site_id'])
            ->where('l.name', $name)
            ->value('l.id');
        if ($lotteryId < 1) return json(['code' => 0, 'message' => 'ok', 'data' => ['list' => []]]);

        $configuredLimit = (int)Db::name('settings')
            ->where('site_id', (int)$session['site_id'])
            ->where('key', 'draw_history_limit')
            ->value('value');
        $limit = $configuredLimit > 0 ? min(200, $configuredLimit) : 80;
        $controls = json_decode((string)Db::name('settings')->where('site_id', (int)$session['site_id'])->where('key', 'lottery_betting_controls')->value('value'), true);
        $control = is_array($controls) ? ($controls[(string)$lotteryId] ?? []) : [];
        $showNext = $this->timingState(is_array($control) ? $control : [])['show_next_issue'];

        // When the next pending issue is hidden, fetch extra rows so the
        // configured number of opened records is still returned.
        $fetchSize = $showNext ? $limit : min(220, $limit + 20);
        $histories = Db::name('lottery_histories')
            ->where('lottery_id', $lotteryId)
            ->order('open_time desc')
            ->order('id desc')
            ->limit($fetchSize)
            ->select()
            ->toArray();

        $list = [];
        foreach ($histories as $history) {
            $opened = (int)($history['is_opened'] ?? 1) === 1;
            $hasNumbers = $opened && trim((string)($history['numbers'] ?? '')) !== '';
            if (!$showNext && !$hasNumbers) continue;

            $numbers = [];
            foreach (['one', 'two', 'three'] as $field) {
                if (($history[$field] ?? null) !== null && (string)$history[$field] !== '') $numbers[] = (int)$history[$field];
            }
            if (count($numbers) < 3) {
                $parts = preg_split('/[,，\s]+/u', trim((string)($history['numbers'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $numbers = array_map('intval', array_slice($parts, 0, 3));
            }
            $complete = count($numbers) === 3;
            $sum = $complete ? array_sum($numbers) : null;
            $list[] = [
                'lottery' => $name,
                'issue_no' => (string)$history['code'],
                'draw_time' => $history['open_time'] ?? null,
                'numbers' => $opened && $complete ? implode(',', $numbers) : '',
                'sum_value' => $opened ? $sum : null,
                'size' => $opened && $complete ? ($sum >= 14 ? '大' : '小') : null,
                'parity' => $opened && $complete ? ($sum % 2 ? '单' : '双') : null,
                'span_value' => $opened && $complete ? max($numbers) - min($numbers) : null,
                'pending' => $opened ? 0 : 1,
            ];
            if (count($list) >= $limit) break;
        }
        return json(['code' => 0, 'message' => 'ok', 'data' => ['list' => $list]]);
    }

    private function timingState(array $control, ?int $now = null): array
    {
        $now ??= time();
        $minutes = (int)date('H', $now) * 60 + (int)date('i', $now);
        foreach ((array)($control['timing_rules'] ?? []) as $rule) {
            if (!is_array($rule)) continue;
            [$sh, $sm] = array_map('intval', explode(':', (string)($rule['start_time'] ?? '00:00')));
            [$eh, $em] = array_map('intval', explode(':', (string)($rule['end_time'] ?? '23:59')));
            $start = $sh * 60 + $sm; $end = $eh * 60 + $em;
            $in = $start === $end ? true : ($start < $end ? ($minutes >= $start && $minutes < $end) : ($minutes >= $start || $minutes < $end));
            if ($in) return ['show_next_issue' => (int)($rule['show_next_issue'] ?? 1) === 1];
        }
        return ['show_next_issue' => true];
    }
}
