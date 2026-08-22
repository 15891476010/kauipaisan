<?php
declare(strict_types=1);

use think\migration\Migrator;

final class CreateAccountSessions extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `account_sessions` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`token_hash` CHAR(64) NOT NULL,`account_type` VARCHAR(40) NOT NULL,`account_id` BIGINT UNSIGNED NOT NULL,`tenant_id` BIGINT UNSIGNED NULL,`agent_id` BIGINT UNSIGNED NULL,`site_id` BIGINT UNSIGNED NULL,`ip` VARCHAR(45) NULL,`location` VARCHAR(180) NULL,`device` VARCHAR(240) NULL,`user_agent` VARCHAR(500) NULL,`login_at` DATETIME NOT NULL,`last_seen_at` DATETIME NOT NULL,`logged_out_at` DATETIME NULL,UNIQUE KEY `uk_account_session_token` (`token_hash`),INDEX `idx_account_session_account` (`account_type`,`account_id`,`login_at`),INDEX `idx_account_session_online` (`account_type`,`account_id`,`last_seen_at`,`logged_out_at`),INDEX `idx_account_session_site` (`site_id`,`account_type`,`last_seen_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `account_sessions`');
    }
}
