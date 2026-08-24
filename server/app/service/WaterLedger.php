<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/** Reporting-only offline-water ledger; it deliberately never mutates balances. */
final class WaterLedger
{
    /** @return array{base_amount:float,water_rate:float,amount:float} */
    public static function calculate(float $baseAmount, float $waterRate): array
    {
        $baseAmount = round(max(0.0, $baseAmount), 2);
        $waterRate = round(max(0.0, $waterRate), 4);
        return [
            'base_amount' => $baseAmount,
            'water_rate' => $waterRate,
            'amount' => round($baseAmount * $waterRate, 2),
        ];
    }

    /** Write one detail's realized offline water inside the settlement transaction. */
    public static function recordForDetail(array $record, array $user, array $detail, array $stop): void
    {
        $calculated = self::calculate((float)($detail['amount'] ?? 0), (float)($stop['drop_odds'] ?? 0));
        if ($calculated['amount'] < 0.005 || $calculated['water_rate'] <= 0.0) return;

        $organizationId = (int)($user['organization_id'] ?? 0);
        if ($organizationId < 1) {
            $root = OrganizationHierarchy::rootForSite((int)($record['site_id'] ?? 0));
            $organizationId = (int)($root['id'] ?? 0);
        }
        if ($organizationId < 1) throw new \RuntimeException('赚水流水缺少归属组织');

        // The unique key makes retries and concurrent settlement idempotent.
        Db::execute(
            'INSERT IGNORE INTO `organization_water_ledger` '
            .'(`tenant_id`,`site_id`,`organization_id`,`line_organization_id`,`related_user_id`,`related_bet_record_id`,`related_bet_detail_id`,`issue_no`,`base_amount`,`water_rate`,`amount`,`source_type`,`reason`,`created_at`) '
            .'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                (int)($record['tenant_id'] ?? 0),
                (int)($record['site_id'] ?? 0),
                $organizationId,
                $organizationId,
                (int)($record['user_id'] ?? 0) ?: null,
                (int)($record['id'] ?? 0) ?: null,
                (int)($detail['id'] ?? 0),
                (string)($detail['issue_no'] ?? $record['issue_no'] ?? ''),
                number_format($calculated['base_amount'], 2, '.', ''),
                number_format($calculated['water_rate'], 4, '.', ''),
                number_format($calculated['amount'], 2, '.', ''),
                'water_profit',
                '离线赚水',
                date('Y-m-d H:i:s'),
            ],
        );
    }
}
