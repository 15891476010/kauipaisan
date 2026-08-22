<?php
declare(strict_types=1);
use think\migration\Migrator;

final class CreateSaasTables extends Migrator
{
    public function up(): void
    {
        $tables = [
            'tenants' => "CREATE TABLE IF NOT EXISTS `tenants` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`name` VARCHAR(120) NOT NULL,`code` VARCHAR(64) NOT NULL UNIQUE,`status` TINYINT NOT NULL DEFAULT 1,`created_at` DATETIME NULL,`updated_at` DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'agents' => "CREATE TABLE IF NOT EXISTS `agents` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL,`parent_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,`name` VARCHAR(120) NOT NULL,`code` VARCHAR(64) NOT NULL UNIQUE,`level` TINYINT NOT NULL DEFAULT 1,`status` TINYINT NOT NULL DEFAULT 1,`created_at` DATETIME NULL,`updated_at` DATETIME NULL,INDEX `idx_agent_scope` (`tenant_id`,`parent_id`,`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'sites' => "CREATE TABLE IF NOT EXISTS `sites` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL,`agent_id` BIGINT UNSIGNED NOT NULL,`name` VARCHAR(120) NOT NULL,`code` VARCHAR(64) NOT NULL,`status` TINYINT NOT NULL DEFAULT 1,`settings` JSON NULL,`created_at` DATETIME NULL,`updated_at` DATETIME NULL,UNIQUE KEY `uk_site_agent_code` (`agent_id`,`code`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'domains' => "CREATE TABLE IF NOT EXISTS `domains` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL,`agent_id` BIGINT UNSIGNED NOT NULL,`site_id` BIGINT UNSIGNED NOT NULL,`domain` VARCHAR(253) NOT NULL UNIQUE,`is_primary` TINYINT NOT NULL DEFAULT 0,`status` TINYINT NOT NULL DEFAULT 1,`created_at` DATETIME NULL,`updated_at` DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'users' => "CREATE TABLE IF NOT EXISTS `users` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NULL,`agent_id` BIGINT UNSIGNED NULL,`username` VARCHAR(80) NOT NULL UNIQUE,`display_name` VARCHAR(120) NOT NULL,`password` VARCHAR(255) NOT NULL,`user_type` ENUM('admin','member') NOT NULL DEFAULT 'member',`status` TINYINT NOT NULL DEFAULT 1,`last_login_at` DATETIME NULL,`created_at` DATETIME NULL,`updated_at` DATETIME NULL,INDEX `idx_user_scope` (`tenant_id`,`agent_id`,`user_type`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'roles' => "CREATE TABLE IF NOT EXISTS `roles` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NULL,`name` VARCHAR(80) NOT NULL,`code` VARCHAR(80) NOT NULL,`status` TINYINT NOT NULL DEFAULT 1,`created_at` DATETIME NULL,`updated_at` DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'menus' => "CREATE TABLE IF NOT EXISTS `menus` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`parent_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,`title` VARCHAR(80) NOT NULL,`name` VARCHAR(80) NOT NULL,`path` VARCHAR(160) NOT NULL,`component` VARCHAR(160) NOT NULL DEFAULT 'ResourceView',`icon` VARCHAR(60) NULL,`sort` INT NOT NULL DEFAULT 0,`status` TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'role_menus' => "CREATE TABLE IF NOT EXISTS `role_menus` (`role_id` BIGINT UNSIGNED NOT NULL,`menu_id` BIGINT UNSIGNED NOT NULL,PRIMARY KEY (`role_id`,`menu_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'tokens' => "CREATE TABLE IF NOT EXISTS `tokens` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`user_id` BIGINT UNSIGNED NOT NULL,`token_hash` CHAR(64) NOT NULL UNIQUE,`expires_at` DATETIME NOT NULL,`revoked_at` DATETIME NULL,`created_at` DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'audit_logs' => "CREATE TABLE IF NOT EXISTS `audit_logs` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NULL,`agent_id` BIGINT UNSIGNED NULL,`user_id` BIGINT UNSIGNED NULL,`username` VARCHAR(80) NULL,`action` VARCHAR(80) NOT NULL,`resource` VARCHAR(120) NULL,`ip` VARCHAR(45) NULL,`payload` JSON NULL,`created_at` DATETIME NOT NULL,INDEX `idx_audit_scope` (`tenant_id`,`agent_id`,`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'system_settings' => "CREATE TABLE IF NOT EXISTS `settings` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NULL,`key` VARCHAR(120) NOT NULL,`value` TEXT NULL,`updated_at` DATETIME NULL,UNIQUE KEY `uk_setting_scope_key` (`tenant_id`,`key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($tables as $sql) $this->execute($sql);
    }
    public function down(): void { foreach (['audit_logs','tokens','role_menus','menus','roles','users','domains','sites','agents','tenants','settings'] as $table) $this->execute('DROP TABLE IF EXISTS `'.$table.'`'); }
}
