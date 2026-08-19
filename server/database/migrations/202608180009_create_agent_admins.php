<?php
declare(strict_types=1);

use think\migration\Migrator;

final class CreateAgentAdmins extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `agent_admins` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL,`agent_id` BIGINT UNSIGNED NOT NULL,`username` VARCHAR(80) NOT NULL,`display_name` VARCHAR(120) NOT NULL,`phone` VARCHAR(30) NULL,`password` VARCHAR(255) NOT NULL,`status` TINYINT NOT NULL DEFAULT 1,`last_login_at` DATETIME NULL,`deleted_at` DATETIME NULL,`created_at` DATETIME NULL,`updated_at` DATETIME NULL,UNIQUE KEY `uk_agent_admin_username` (`agent_id`,`username`),INDEX `idx_agent_admin_login` (`username`,`status`,`deleted_at`),INDEX `idx_agent_admin_scope` (`tenant_id`,`agent_id`,`deleted_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `agent_admins`');
    }
}
