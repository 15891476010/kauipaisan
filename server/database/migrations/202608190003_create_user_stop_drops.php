<?php
declare(strict_types=1);
use think\migration\Migrator;

final class CreateUserStopDrops extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `user_stop_drops` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL,`site_id` BIGINT UNSIGNED NOT NULL,`user_id` BIGINT UNSIGNED NOT NULL,`bet_detail_id` BIGINT UNSIGNED NULL,`lottery` VARCHAR(20) NOT NULL,`issue_no` VARCHAR(40) NOT NULL,`number_text` TEXT NOT NULL,`play_type` VARCHAR(80) NOT NULL DEFAULT '',`stop_type` VARCHAR(10) NOT NULL DEFAULT 'none',`original_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,`actual_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,`stop_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,`original_odds` DECIMAL(10,3) NULL,`actual_odds` DECIMAL(10,3) NULL,`drop_odds` DECIMAL(10,3) NULL,`source_text` TEXT NULL,`placed_at` DATETIME NOT NULL,`created_at` DATETIME NOT NULL,INDEX `idx_stop_drop_user_time` (`site_id`,`user_id`,`placed_at`),INDEX `idx_stop_drop_filters` (`site_id`,`user_id`,`lottery`,`stop_type`,`play_type`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    public function down(): void { $this->execute('DROP TABLE IF EXISTS `user_stop_drops`'); }
}
