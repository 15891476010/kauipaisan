<?php
declare(strict_types=1);
use think\migration\Migrator;

final class CreateUserLotteryPermissions extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `user_lottery_permissions` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,`site_id` BIGINT UNSIGNED NOT NULL,`user_id` BIGINT UNSIGNED NOT NULL,`lottery_id` BIGINT UNSIGNED NOT NULL,`can_view` TINYINT(1) NOT NULL DEFAULT 1,`can_bet` TINYINT(1) NOT NULL DEFAULT 1,`created_at` DATETIME NOT NULL,`updated_at` DATETIME NOT NULL,UNIQUE KEY `uk_user_lottery_permission` (`user_id`,`lottery_id`),INDEX `idx_user_lottery_site_user` (`site_id`,`user_id`),INDEX `idx_user_lottery_lottery` (`lottery_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `user_lottery_permissions`');
    }
}
