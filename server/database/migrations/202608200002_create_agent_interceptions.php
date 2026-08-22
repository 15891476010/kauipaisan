<?php
declare(strict_types=1);

use think\migration\Migrator;

final class CreateAgentInterceptions extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `interception_capacity_usage` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL,`scope_type` VARCHAR(16) NOT NULL,`scope_id` BIGINT UNSIGNED NOT NULL,`lottery_id` BIGINT UNSIGNED NOT NULL,`lottery_odds_id` BIGINT UNSIGNED NOT NULL,`issue_no` VARCHAR(40) NOT NULL,`number_key` VARCHAR(64) NOT NULL,`used_amount` DECIMAL(14,4) NOT NULL DEFAULT 0,`updated_at` DATETIME NOT NULL,UNIQUE KEY `uk_interception_capacity` (`tenant_id`,`scope_type`,`scope_id`,`lottery_id`,`lottery_odds_id`,`issue_no`,`number_key`),INDEX `idx_interception_capacity_issue` (`lottery_id`,`issue_no`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->execute("CREATE TABLE IF NOT EXISTS `agent_interceptions` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL,`site_id` BIGINT UNSIGNED NOT NULL,`user_id` BIGINT UNSIGNED NOT NULL,`lottery_id` BIGINT UNSIGNED NOT NULL,`lottery_odds_id` BIGINT UNSIGNED NOT NULL,`bet_record_id` BIGINT UNSIGNED NOT NULL,`bet_detail_id` BIGINT UNSIGNED NOT NULL,`issue_no` VARCHAR(40) NOT NULL,`number_key` VARCHAR(64) NOT NULL,`bet_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,`share_rate` DECIMAL(8,4) NOT NULL DEFAULT 0,`requested_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,`intercepted_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,`site_limit` DECIMAL(14,2) NOT NULL DEFAULT 0,`platform_limit` DECIMAL(14,2) NOT NULL DEFAULT 0,`follow_platform` TINYINT NOT NULL DEFAULT 0,`allocation_status` VARCHAR(24) NOT NULL DEFAULT 'allocated',`created_at` DATETIME NOT NULL,`released_at` DATETIME NULL,INDEX `idx_agent_interception_record` (`bet_record_id`,`bet_detail_id`),INDEX `idx_agent_interception_site_issue` (`site_id`,`lottery_id`,`issue_no`),INDEX `idx_agent_interception_platform_issue` (`tenant_id`,`lottery_id`,`issue_no`,`lottery_odds_id`,`number_key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `agent_interceptions`');
        $this->execute('DROP TABLE IF EXISTS `interception_capacity_usage`');
    }
}
