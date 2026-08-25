<?php
declare(strict_types=1);

use think\migration\Migrator;

final class AddSystemLotteryMode extends Migrator
{
    public function up(): void
    {
        $table=$this->table('lotteries');
        if (!$table->hasColumn('source_type')) $this->execute("ALTER TABLE `lotteries` ADD COLUMN `source_type` VARCHAR(20) NOT NULL DEFAULT 'official' AFTER `code`");
        if (!$table->hasColumn('system_interval_seconds')) $this->execute("ALTER TABLE `lotteries` ADD COLUMN `system_interval_seconds` INT NOT NULL DEFAULT 60 AFTER `source_type`");
        if (!$table->hasColumn('system_initial_issue')) $this->execute("ALTER TABLE `lotteries` ADD COLUMN `system_initial_issue` VARCHAR(40) NULL AFTER `system_interval_seconds`");
        if (!$table->hasColumn('odds_source_lottery_id')) $this->execute("ALTER TABLE `lotteries` ADD COLUMN `odds_source_lottery_id` BIGINT UNSIGNED NULL AFTER `system_initial_issue`");
        $history=$this->table('lottery_histories');
        if (!$history->hasColumn('is_opened')) $this->execute("ALTER TABLE `lottery_histories` ADD COLUMN `is_opened` TINYINT NOT NULL DEFAULT 1 AFTER `numbers`");
        $this->execute("UPDATE `lottery_histories` SET `is_opened`=CASE WHEN `numbers` IS NULL OR `numbers`='' THEN 0 ELSE 1 END");
    }

    public function down(): void
    {
        if ($this->table('lottery_histories')->hasColumn('is_opened')) $this->execute("ALTER TABLE `lottery_histories` DROP COLUMN `is_opened`");
        foreach (['odds_source_lottery_id','system_initial_issue','system_interval_seconds','source_type'] as $column) if ($this->table('lotteries')->hasColumn($column)) $this->execute("ALTER TABLE `lotteries` DROP COLUMN `{$column}`");
    }
}
