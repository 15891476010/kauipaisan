<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

/** Make interception capacity apply to a whole play for an issue. */
final class AggregateInterceptionCapacity extends Migrator
{
    public function up(): void
    {
        $marker = '__PLAY_TOTAL__';
        $rows = Db::name('interception_capacity_usage')
            ->where('number_key', '<>', $marker)
            ->field('tenant_id,scope_type,scope_id,lottery_id,lottery_odds_id,issue_no,SUM(used_amount) AS used_amount,MAX(updated_at) AS updated_at')
            ->group('tenant_id,scope_type,scope_id,lottery_id,lottery_odds_id,issue_no')
            ->select()->toArray();
        foreach ($rows as $row) {
            Db::execute(
                'INSERT INTO interception_capacity_usage (tenant_id,scope_type,scope_id,lottery_id,lottery_odds_id,issue_no,number_key,used_amount,updated_at) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE used_amount=used_amount+VALUES(used_amount),updated_at=VALUES(updated_at)',
                [(int)$row['tenant_id'],(string)$row['scope_type'],(int)$row['scope_id'],(int)$row['lottery_id'],(int)$row['lottery_odds_id'],(string)$row['issue_no'],$marker,(float)$row['used_amount'],(string)$row['updated_at']]
            );
        }
        if ($rows !== []) Db::name('interception_capacity_usage')->where('number_key', '<>', $marker)->delete();
    }

    public function down(): void
    {
        // Historical per-number usage cannot be reconstructed from aggregate usage.
    }
}
