<?php
declare(strict_types=1);
use think\migration\Migrator;

final class SplitSiteAccounts extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `admins` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NULL,`username` VARCHAR(80) NOT NULL,`display_name` VARCHAR(120) NOT NULL,`password` VARCHAR(255) NOT NULL,`status` TINYINT NOT NULL DEFAULT 1,`last_login_at` DATETIME NULL,`deleted_at` DATETIME NULL,`created_at` DATETIME NULL,`updated_at` DATETIME NULL,UNIQUE KEY `uk_admin_username` (`username`),INDEX `idx_admin_deleted` (`deleted_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->execute("CREATE TABLE IF NOT EXISTS `site_admins` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL,`site_id` BIGINT UNSIGNED NOT NULL,`username` VARCHAR(80) NOT NULL,`display_name` VARCHAR(120) NOT NULL,`phone` VARCHAR(30) NULL,`password` VARCHAR(255) NOT NULL,`status` TINYINT NOT NULL DEFAULT 1,`last_login_at` DATETIME NULL,`deleted_at` DATETIME NULL,`created_at` DATETIME NULL,`updated_at` DATETIME NULL,UNIQUE KEY `uk_site_admin_username` (`site_id`,`username`),INDEX `idx_site_admin_deleted` (`site_id`,`deleted_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->execute("CREATE TABLE IF NOT EXISTS `site_users` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL,`site_id` BIGINT UNSIGNED NOT NULL,`username` VARCHAR(80) NOT NULL,`display_name` VARCHAR(120) NOT NULL,`phone` VARCHAR(30) NULL,`password` VARCHAR(255) NOT NULL,`status` TINYINT NOT NULL DEFAULT 1,`last_login_at` DATETIME NULL,`deleted_at` DATETIME NULL,`created_at` DATETIME NULL,`updated_at` DATETIME NULL,UNIQUE KEY `uk_site_user_username` (`site_id`,`username`),INDEX `idx_site_user_deleted` (`site_id`,`deleted_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->execute("ALTER TABLE `sites` ADD COLUMN `deleted_at` DATETIME NULL AFTER `updated_at`");
        $this->execute("INSERT IGNORE INTO `admins` (`tenant_id`,`username`,`display_name`,`password`,`status`,`created_at`,`updated_at`) SELECT `tenant_id`,`username`,`display_name`,`password`,`status`,`created_at`,`updated_at` FROM `users` WHERE `user_type`='admin'");
        $this->execute("INSERT IGNORE INTO `site_admins` (`tenant_id`,`site_id`,`username`,`display_name`,`phone`,`password`,`status`,`created_at`,`updated_at`) SELECT s.tenant_id,s.id,s.manager_username,s.manager_username,s.manager_phone,s.manager_password,s.status,s.created_at,s.updated_at FROM sites s WHERE s.manager_username IS NOT NULL AND s.manager_username <> ''");
    }
    public function down(): void { $this->execute('DROP TABLE IF EXISTS `site_users`'); $this->execute('DROP TABLE IF EXISTS `site_admins`'); $this->execute('DROP TABLE IF EXISTS `admins`'); }
}
