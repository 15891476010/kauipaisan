<?php
declare(strict_types=1);

use think\migration\Migrator;

final class CreateAgentSubaccounts extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `agent_subaccounts` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL,`site_id` BIGINT UNSIGNED NOT NULL,`agent_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,`username` VARCHAR(80) NOT NULL,`display_name` VARCHAR(120) NOT NULL,`password` VARCHAR(255) NOT NULL,`permissions` TEXT NULL,`lottery_permissions` TEXT NULL,`report_limit_enabled` TINYINT NOT NULL DEFAULT 0,`report_from_issue` VARCHAR(40) NULL,`report_to_issue` VARCHAR(40) NULL,`status` TINYINT NOT NULL DEFAULT 1,`last_login_at` DATETIME NULL,`created_at` DATETIME NOT NULL,`updated_at` DATETIME NOT NULL,`deleted_at` DATETIME NULL,UNIQUE KEY `uk_agent_subaccount_site_username` (`site_id`,`username`),INDEX `idx_agent_subaccount_login` (`username`,`status`,`deleted_at`),INDEX `idx_agent_subaccount_site` (`site_id`,`deleted_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `agent_subaccounts`');
    }
}
