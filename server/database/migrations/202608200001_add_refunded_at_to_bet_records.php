<?php
declare(strict_types=1);

use think\migration\Migrator;

final class AddRefundedAtToBetRecords extends Migrator
{
    public function up(): void
    {
        $this->execute("ALTER TABLE `bet_records` ADD COLUMN `refunded_at` DATETIME NULL AFTER `placed_at`, ADD INDEX `idx_bet_refunded` (`site_id`,`status`,`refunded_at`)");
        $this->execute("UPDATE `bet_records` SET `refunded_at`=`placed_at` WHERE `status`='refunded' AND `refunded_at` IS NULL");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE `bet_records` DROP INDEX `idx_bet_refunded`, DROP COLUMN `refunded_at`");
    }
}
