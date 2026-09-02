<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

/** Indexes used by the management list and the user/agent detail joins. */
final class AddBetQueryIndexes extends Migrator
{
    public function up(): void
    {
        $this->addIndexIfMissing('bet_records', 'idx_bet_site_id', '(`site_id`,`id`)');
        $this->addIndexIfMissing('bet_records', 'idx_bet_issue_status', '(`issue_no`,`status`)');
        $this->addIndexIfMissing('bet_details', 'idx_detail_record', '(`bet_record_id`)');
        $this->addIndexIfMissing('user_stop_drops', 'idx_stop_detail_lottery', '(`bet_detail_id`,`lottery`)');
        $this->addIndexIfMissing('user_stop_drops', 'idx_stop_lottery_detail', '(`lottery`,`bet_detail_id`)');
    }

    private function addIndexIfMissing(string $table, string $name, string $columns): void
    {
        $exists = Db::query("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$name]);
        if ($exists === []) $this->execute("ALTER TABLE `{$table}` ADD INDEX `{$name}` {$columns}");
    }

    public function down(): void
    {
        foreach ([
            'bet_records' => ['idx_bet_site_id', 'idx_bet_issue_status'],
            'bet_details' => ['idx_detail_record'],
            'user_stop_drops' => ['idx_stop_detail_lottery', 'idx_stop_lottery_detail'],
        ] as $table => $indexes) {
            foreach ($indexes as $index) {
                $exists = Db::query("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]);
                if ($exists !== []) $this->execute("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
            }
        }
    }
}
