<?php
declare(strict_types=1);
use think\migration\Migrator;

final class AddLotteryBettingControls extends Migrator
{
    public function up(): void
    {
        $this->execute("ALTER TABLE `lotteries`
            ADD COLUMN `cutoff_enabled` TINYINT NOT NULL DEFAULT 0 AFTER `status`,
            ADD COLUMN `cutoff_time` VARCHAR(5) NULL AFTER `cutoff_enabled`,
            ADD COLUMN `mask_enabled` TINYINT NOT NULL DEFAULT 1 AFTER `cutoff_time`,
            ADD COLUMN `refund_enabled` TINYINT NOT NULL DEFAULT 1 AFTER `mask_enabled`");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE `lotteries`
            DROP COLUMN `refund_enabled`, DROP COLUMN `mask_enabled`,
            DROP COLUMN `cutoff_time`, DROP COLUMN `cutoff_enabled`");
    }
}
