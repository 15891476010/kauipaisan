<?php
declare(strict_types=1);

namespace app\service;

/** 报表展示：结算前保留货量，但不把尚未开奖的投注当成亏损。 */
final class ReportSettlementVisibility
{
    public static function apply(array $metrics, bool $settled): array
    {
        if ($settled) {
            return $metrics;
        }
        foreach (['win_amount', 'member_profit', 'share_profit', 'agent_profit', 'platform_profit'] as $key) {
            $metrics[$key] = 0.0;
        }
        foreach ($metrics['levels'] ?? [] as $level => $values) {
            $metrics['levels'][$level]['profit'] = 0.0;
            $metrics['levels'][$level]['share_profit'] = 0.0;
        }
        return $metrics;
    }
}
