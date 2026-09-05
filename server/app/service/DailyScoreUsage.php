<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/** Keeps member betting usage separate from the fixed credit allocation. */
final class DailyScoreUsage
{
    public static function today(): string
    {
        return date('Y-m-d');
    }

    /** Reset stale usage on first access after the local business day changes. */
    public static function normalize(array $user): array
    {
        $today = self::today();
        $usageDate = trim((string)($user['used_balance_date'] ?? ''));
        if ($usageDate === $today) return $user;
        $id = (int)($user['id'] ?? 0);
        if ($id < 1) {
            $user['used_balance'] = 0.0;
            $user['used_balance_date'] = $today;
            return $user;
        }
        Db::name('site_users')->where('id', $id)->update([
            'used_balance' => '0.00',
            'used_balance_date' => $today,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $user['used_balance'] = 0.0;
        $user['used_balance_date'] = $today;
        return $user;
    }

    /** Apply a usage delta to the current business day. */
    public static function change(int $userId, float $delta): void
    {
        if ($userId < 1 || abs($delta) < 0.000001) return;
        $today = self::today();
        $amount = number_format(abs($delta), 2, '.', '');
        $expression = $delta >= 0
            ? "CASE WHEN used_balance_date = '{$today}' THEN used_balance + {$amount} ELSE {$amount} END"
            : "GREATEST(CASE WHEN used_balance_date = '{$today}' THEN used_balance - {$amount} ELSE 0 END, 0)";
        Db::name('site_users')->where('id', $userId)->update([
            'used_balance' => Db::raw($expression),
            'used_balance_date' => $today,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Release usage only when the original bet belongs to today's business day. */
    public static function changeForPlacedAt(int $userId, float $delta, ?string $placedAt): void
    {
        $date = trim((string)$placedAt);
        if ($date === '' || substr($date, 0, 10) !== self::today()) return;
        self::change($userId, $delta);
    }
}
