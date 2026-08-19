<?php
declare(strict_types=1);
use think\migration\Migrator;
final class CreateLotteryHistories extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `lottery_histories` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,`lottery_id` BIGINT UNSIGNED NOT NULL,`code` VARCHAR(40) NOT NULL,`draw_day` DATE NULL,`one` TINYINT NULL,`two` TINYINT NULL,`three` TINYINT NULL,`numbers` VARCHAR(80) NOT NULL,`open_time` DATETIME NULL,`next_open_time` DATETIME NULL,`next_code` VARCHAR(40) NULL,`created_at` DATETIME NOT NULL,`updated_at` DATETIME NOT NULL,UNIQUE KEY `uk_lottery_history_code` (`lottery_id`,`code`),INDEX `idx_lottery_history_time` (`lottery_id`,`draw_day`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    public function down(): void { $this->execute('DROP TABLE IF EXISTS `lottery_histories`'); }
}
