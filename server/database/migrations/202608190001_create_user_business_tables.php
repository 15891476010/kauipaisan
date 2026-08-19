<?php
declare(strict_types=1);
use think\migration\Migrator;

final class CreateUserBusinessTables extends Migrator
{
    public function up(): void
    {
        $tables = [
            'bet_records' => "CREATE TABLE IF NOT EXISTS `bet_records` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL,`site_id` BIGINT UNSIGNED NOT NULL,`user_id` BIGINT UNSIGNED NOT NULL,`issue_no` VARCHAR(40) NOT NULL,`source_text` TEXT NULL,`bet_count` INT NOT NULL DEFAULT 0,`amount` DECIMAL(12,2) NOT NULL DEFAULT 0,`win_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,`status` VARCHAR(20) NOT NULL DEFAULT 'pending',`sealed` TINYINT NOT NULL DEFAULT 0,`placed_at` DATETIME NOT NULL,`created_at` DATETIME NOT NULL,INDEX `idx_bet_user_time` (`site_id`,`user_id`,`placed_at`),INDEX `idx_bet_issue` (`site_id`,`issue_no`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'bet_details' => "CREATE TABLE IF NOT EXISTS `bet_details` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL,`site_id` BIGINT UNSIGNED NOT NULL,`user_id` BIGINT UNSIGNED NOT NULL,`bet_record_id` BIGINT UNSIGNED NULL,`issue_no` VARCHAR(40) NOT NULL,`number_text` TEXT NOT NULL,`category` VARCHAR(80) NULL,`amount` DECIMAL(12,2) NOT NULL DEFAULT 0,`odds` DECIMAL(10,3) NULL,`win_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,`rebate` DECIMAL(12,2) NOT NULL DEFAULT 0,`status` VARCHAR(20) NOT NULL DEFAULT 'pending',`placed_at` DATETIME NOT NULL,`source_text` TEXT NULL,INDEX `idx_detail_user_time` (`site_id`,`user_id`,`placed_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'bills' => "CREATE TABLE IF NOT EXISTS `bills` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL,`site_id` BIGINT UNSIGNED NOT NULL,`user_id` BIGINT UNSIGNED NOT NULL,`bill_date` DATE NOT NULL,`bet_count` INT NOT NULL DEFAULT 0,`amount` DECIMAL(12,2) NOT NULL DEFAULT 0,`rebate` DECIMAL(12,2) NOT NULL DEFAULT 0,`offline_rebate` DECIMAL(12,2) NOT NULL DEFAULT 0,`win_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,`profit` DECIMAL(12,2) NOT NULL DEFAULT 0,`created_at` DATETIME NOT NULL,UNIQUE KEY `uk_bill_user_date` (`site_id`,`user_id`,`bill_date`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'lottery_draws' => "CREATE TABLE IF NOT EXISTS `lottery_draws` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL,`site_id` BIGINT UNSIGNED NOT NULL,`lottery` VARCHAR(20) NOT NULL,`issue_no` VARCHAR(40) NOT NULL,`draw_date` DATE NOT NULL,`draw_time` DATETIME NULL,`numbers` VARCHAR(80) NOT NULL,`sum_value` INT NULL,`size` VARCHAR(10) NULL,`parity` VARCHAR(10) NULL,`created_at` DATETIME NOT NULL,UNIQUE KEY `uk_draw_lottery_issue` (`site_id`,`lottery`,`issue_no`),INDEX `idx_draw_date` (`site_id`,`lottery`,`draw_date`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($tables as $sql) $this->execute($sql);
    }
    public function down(): void { foreach (['lottery_draws','bills','bet_details','bet_records'] as $table) $this->execute('DROP TABLE IF EXISTS `'.$table.'`'); }
}
