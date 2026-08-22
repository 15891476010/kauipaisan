<?php
declare(strict_types=1);
use think\migration\Migrator;
final class CreateLotteryOdds extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `lottery_odds` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,`lottery_id` BIGINT UNSIGNED NOT NULL,`category` VARCHAR(80) NOT NULL,`name` VARCHAR(120) NOT NULL,`odds` DECIMAL(12,4) NOT NULL DEFAULT 0,`status` TINYINT NOT NULL DEFAULT 1,`sort` INT NOT NULL DEFAULT 0,`created_at` DATETIME NULL,`updated_at` DATETIME NULL,`deleted_at` DATETIME NULL,INDEX `idx_odds_lottery` (`lottery_id`,`status`,`sort`),UNIQUE KEY `uk_odds_lottery_code` (`lottery_id`,`category`,`name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    public function down(): void { $this->execute('DROP TABLE IF EXISTS `lottery_odds`'); }
}
