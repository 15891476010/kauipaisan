<?php
declare(strict_types=1);

use think\migration\Migrator;

final class CreateRobotAccounts extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `robot_accounts` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `tenant_id` BIGINT UNSIGNED NOT NULL,
            `site_id` BIGINT UNSIGNED NOT NULL,
            `organization_id` BIGINT UNSIGNED NOT NULL,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `username` VARCHAR(80) NOT NULL,
            `plain_password` VARCHAR(255) NOT NULL,
            `min_amount` DECIMAL(14,2) NOT NULL DEFAULT 1.00,
            `max_amount` DECIMAL(14,2) NOT NULL DEFAULT 100.00,
            `amount_precision` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `start_at` DATETIME NOT NULL,
            `next_run_at` DATETIME NULL,
            `last_bet_at` DATETIME NULL,
            `interval_min` INT UNSIGNED NOT NULL DEFAULT 3,
            `interval_max` INT UNSIGNED NOT NULL DEFAULT 5,
            `weight_fu` DECIMAL(8,2) NOT NULL DEFAULT 1.00,
            `weight_ti` DECIMAL(8,2) NOT NULL DEFAULT 1.00,
            `weight_futi` DECIMAL(8,2) NOT NULL DEFAULT 1.00,
            `lottery_configs` JSON NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT 'stopped',
            `converted_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            UNIQUE KEY `uk_robot_site_username` (`site_id`,`username`),
            UNIQUE KEY `uk_robot_user` (`user_id`),
            INDEX `idx_robot_schedule` (`status`,`next_run_at`),
            INDEX `idx_robot_scope` (`tenant_id`,`site_id`,`organization_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `robot_accounts`');
    }
}
