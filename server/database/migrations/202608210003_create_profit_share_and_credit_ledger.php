<?php
declare(strict_types=1);

use think\migration\Migrator;

final class CreateProfitShareAndCreditLedger extends Migrator
{
    public function up(): void
    {
        $nodes = $this->table('organization_nodes');
        if (!$nodes->hasColumn('balance')) {
            $nodes->addColumn('balance', 'decimal', ['precision' => 18, 'scale' => 2, 'default' => '0.00', 'after' => 'credit_limit'])->save();
            $this->execute('UPDATE organization_nodes SET balance=credit_limit WHERE balance=0');
        }
        $this->execute("CREATE TABLE IF NOT EXISTS `organization_profit_shares` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `tenant_id` BIGINT UNSIGNED NOT NULL,
            `site_id` BIGINT UNSIGNED NOT NULL,
            `parent_organization_id` BIGINT UNSIGNED NOT NULL,
            `child_organization_id` BIGINT UNSIGNED NOT NULL,
            `max_share_rate` DECIMAL(8,4) NOT NULL DEFAULT 0,
            `share_rate` DECIMAL(8,4) NOT NULL DEFAULT 0,
            `status` TINYINT NOT NULL DEFAULT 1,
            `effective_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            UNIQUE KEY `uk_profit_share_edge` (`child_organization_id`),
            INDEX `idx_profit_share_parent` (`site_id`,`parent_organization_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->execute("CREATE TABLE IF NOT EXISTS `organization_credit_ledger` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `tenant_id` BIGINT UNSIGNED NOT NULL,
            `site_id` BIGINT UNSIGNED NOT NULL,
            `organization_id` BIGINT UNSIGNED NULL,
            `account_type` VARCHAR(24) NOT NULL,
            `account_id` BIGINT UNSIGNED NOT NULL,
            `related_user_id` BIGINT UNSIGNED NULL,
            `related_bet_record_id` BIGINT UNSIGNED NULL,
            `related_bet_detail_id` BIGINT UNSIGNED NULL,
            `issue_no` VARCHAR(40) NULL,
            `direction` VARCHAR(8) NOT NULL,
            `amount` DECIMAL(18,2) NOT NULL,
            `balance_before` DECIMAL(18,2) NOT NULL DEFAULT 0,
            `balance_after` DECIMAL(18,2) NOT NULL DEFAULT 0,
            `reason` VARCHAR(120) NOT NULL,
            `source_type` VARCHAR(40) NOT NULL,
            `created_at` DATETIME NOT NULL,
            INDEX `idx_credit_ledger_account` (`site_id`,`account_type`,`account_id`,`created_at`),
            INDEX `idx_credit_ledger_bet` (`related_bet_record_id`,`related_bet_detail_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `organization_credit_ledger`');
        $this->execute('DROP TABLE IF EXISTS `organization_profit_shares`');
        $nodes = $this->table('organization_nodes');
        if ($nodes->hasColumn('balance')) $nodes->removeColumn('balance')->save();
    }
}
