<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class BetSettlement
{
    public function settleForHistory(array $history, array $lottery): array
    {
        $lotteryId = (int)($lottery['id'] ?? 0);
        $issue = trim((string)($history['code'] ?? ''));
        if ($lotteryId < 1 || $issue === '') return ['records' => 0, 'won' => 0];
        $lotteryName = (string)($lottery['name'] ?? '');
        $draw = $this->digits($history);
        if ($draw === '') return ['records' => 0, 'won' => 0];

        $records = Db::name('bet_records')->where('issue_no', $issue)->where('status', 'pending')->select()->toArray();
        $processed = 0; $won = 0;
        foreach ($records as $record) {
            $details = Db::name('bet_details')->where('bet_record_id', (int)$record['id'])->select()->toArray();
            if ($details === []) continue;
            $totalWin = 0.0;
            $matchedLottery = false;
            foreach ($details as $detail) {
                $stop = Db::name('user_stop_drops')->where('bet_detail_id', (int)$detail['id'])->find();
                if (!$stop || (string)$stop['lottery'] !== $lotteryName) continue;
                $matchedLottery = true;
                $numbers = preg_split('/\s+/', trim((string)$detail['number_text'])) ?: [];
                $numbers = array_values(array_filter($numbers, static fn(string $n): bool => preg_match('/^\d{3}$/', $n) === 1));
                if ($numbers === []) continue;
                $odds = $this->oddsFor($lotteryId, (string)($detail['source_text'] ?? ''), count($numbers));
                $stake = (float)$detail['amount'] / max(1, count($numbers));
                $matched = 0;
                foreach ($numbers as $number) if ($this->matches($number, $draw, (string)($detail['source_text'] ?? ''))) $matched++;
                $win = $matched * $stake * $odds;
                $totalWin += $win;
                Db::name('bet_details')->where('id', (int)$detail['id'])->update([
                    'odds' => number_format($odds, 4, '.', ''),
                    'win_amount' => number_format($win, 2, '.', ''),
                    'status' => $win > 0 ? 'won' : 'unwon',
                ]);
                Db::name('user_stop_drops')->where('bet_detail_id', (int)$detail['id'])->update([
                    'actual_odds' => number_format($odds, 3, '.', ''),
                ]);
            }
            if (!$matchedLottery) continue;
            $status = $totalWin > 0 ? 'won' : 'unwon';
            Db::transaction(function () use ($record, $totalWin, $status): void {
                Db::name('bet_records')->where('id', (int)$record['id'])->update([
                    'win_amount' => number_format($totalWin, 2, '.', ''),
                    'status' => $status,
                ]);
                $userId = (int)$record['user_id']; $siteId = (int)$record['site_id'];
                $user = Db::name('site_users')->where('id', $userId)->where('site_id', $siteId)->find();
                if ($user) Db::name('site_users')->where('id', $userId)->where('site_id', $siteId)->update([
                    'balance' => number_format((float)$user['balance'] + $totalWin, 2, '.', ''),
                    'used_balance' => number_format(max(0, (float)$user['used_balance'] - (float)$record['amount']), 2, '.', ''),
                ]);
                $billDate = substr((string)$record['placed_at'], 0, 10);
                $bill = Db::name('bills')->where('site_id', $siteId)->where('user_id', $userId)->where('bill_date', $billDate)->find();
                $amount = (float)$record['amount'];
                if ($bill) Db::name('bills')->where('id', (int)$bill['id'])->update([
                    'bet_count' => (int)$bill['bet_count'] + (int)$record['bet_count'],
                    'amount' => number_format((float)$bill['amount'] + $amount, 2, '.', ''),
                    'win_amount' => number_format((float)$bill['win_amount'] + $totalWin, 2, '.', ''),
                    'profit' => number_format((float)$bill['profit'] + $totalWin - $amount, 2, '.', ''),
                ]);
                else Db::name('bills')->insert([
                    'tenant_id' => (int)$record['tenant_id'], 'site_id' => $siteId, 'user_id' => $userId,
                    'bill_date' => $billDate, 'bet_count' => (int)$record['bet_count'], 'amount' => number_format($amount, 2, '.', ''),
                    'rebate' => '0.00', 'offline_rebate' => '0.00', 'win_amount' => number_format($totalWin, 2, '.', ''),
                    'profit' => number_format($totalWin - $amount, 2, '.', ''), 'created_at' => date('Y-m-d H:i:s'),
                ]);
            });
            $processed++; if ($totalWin > 0) $won++;
        }
        return ['records' => $processed, 'won' => $won];
    }

    private function digits(array $history): string
    {
        $digits = '';
        foreach (['one', 'two', 'three'] as $key) $digits .= (string)($history[$key] ?? '');
        if (preg_match('/^\d{3}$/', $digits)) return $digits;
        return preg_replace('/\D/', '', (string)($history['numbers'] ?? '')) ?: '';
    }

    private function matches(string $number, string $draw, string $source): bool
    {
        if (str_contains($source, '豹子')) return count(array_unique(str_split($draw))) === 1 && $number === $draw;
        if (str_contains($source, '组三')) return count(array_unique(str_split($draw))) === 2 && count(array_unique(str_split($number))) === 2 && count_chars($number, 1) === count_chars($draw, 1);
        if (str_contains($source, '组六')) return count(array_unique(str_split($draw))) === 3 && count(array_unique(str_split($number))) === 3 && count_chars($number, 1) === count_chars($draw, 1);
        return $number === $draw;
    }

    public function oddsFor(int $lotteryId, string $source, int $count): float
    {
        $category = '直选'; $name = '直选单注';
        if (str_contains($source, '豹子')) {
            $category = '和值'; $name = '豹子全包';
        }
        $positions = preg_match_all('/[百十个]/u', $source, $unused);
        if ($positions === 1) {
            $category = '一码定位';
            $name = str_contains($source, '百') ? '百位定位' : (str_contains($source, '十') ? '十位定位' : '个位定位');
        } elseif ($positions === 2) {
            $category = '二码定位';
            $name = str_contains($source, '百') && str_contains($source, '十') ? '百十定位' : (str_contains($source, '百') ? '百个定位' : '十个定位');
        } elseif ($positions >= 3) {
            $category = '三位玩法'; $name = '三码定位';
        } elseif (str_contains($source, '独胆')) {
            $category = '三位玩法'; $name = '独胆';
        } elseif (str_contains($source, '双飞')) {
            $category = '三位玩法'; $name = '双飞';
        } elseif (str_contains($source, '对子')) {
            $category = '三位玩法'; $name = '对子';
        } elseif (str_contains($source, '组三')) {
            $category = '组三多码';
        } elseif (str_contains($source, '组六')) {
            $category = '组六多码';
        }
        $query = Db::name('lottery_odds')->where('lottery_id', $lotteryId)->where('category', $category)->where('status', 1)->whereNull('deleted_at');
        if ($category === '组三多码') $query->whereLike('name', '组三%')->order('sort asc');
        elseif ($category === '组六多码') $query->whereLike('name', '组六%')->order('sort asc');
        else $query->where('name', $name);
        $row = $query->order('sort asc')->find();
        return $row ? (float)$row['odds'] : 0.0;
    }
}
