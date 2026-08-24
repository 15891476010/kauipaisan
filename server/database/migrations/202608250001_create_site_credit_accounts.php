<?php
declare(strict_types=1);

use think\migration\Migrator;

final class CreateSiteCreditAccounts extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `site_credit_accounts` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `tenant_id` BIGINT UNSIGNED NOT NULL,
            `site_id` BIGINT UNSIGNED NOT NULL,
            `total_score` DECIMAL(18,2) NOT NULL DEFAULT 0,
            `balance` DECIMAL(18,2) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            UNIQUE KEY `uk_site_credit_site` (`site_id`),
            KEY `idx_site_credit_tenant` (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Existing organization balances were already deducted from the
        // platform account. Preserve them exactly and initialize the new
        // site pool to the amount already assigned to its root directors.
        $now=date('Y-m-d H:i:s');
        $this->execute("INSERT IGNORE INTO site_credit_accounts(tenant_id,site_id,total_score,balance,created_at,updated_at)
            SELECT s.tenant_id,s.id,COALESCE(SUM(n.credit_limit),0),0,'{$now}','{$now}'
            FROM sites s LEFT JOIN organization_nodes n ON n.site_id=s.id AND n.parent_id=0 AND n.level='director' AND n.deleted_at IS NULL
            WHERE s.deleted_at IS NULL GROUP BY s.id,s.tenant_id");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `site_credit_accounts`');
    }
}
