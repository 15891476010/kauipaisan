<?php
declare(strict_types=1);

use think\migration\Migrator;

/** Persist offline water as a reporting-only ledger. It never changes balances. */
final class CreateOrganizationWaterLedger extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `organization_water_ledger` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `tenant_id` BIGINT UNSIGNED NOT NULL,
            `site_id` BIGINT UNSIGNED NOT NULL,
            `organization_id` BIGINT UNSIGNED NOT NULL,
            `line_organization_id` BIGINT UNSIGNED NULL,
            `related_user_id` BIGINT UNSIGNED NULL,
            `related_bet_record_id` BIGINT UNSIGNED NULL,
            `related_bet_detail_id` BIGINT UNSIGNED NOT NULL,
            `issue_no` VARCHAR(40) NOT NULL,
            `base_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `water_rate` DECIMAL(10,4) NOT NULL DEFAULT 0,
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `source_type` VARCHAR(40) NOT NULL DEFAULT 'water_profit',
            `reason` VARCHAR(120) NOT NULL DEFAULT '离线赚水',
            `created_at` DATETIME NOT NULL,
            UNIQUE KEY `uk_water_detail_org_source` (`related_bet_detail_id`,`organization_id`,`source_type`),
            INDEX `idx_water_org_time` (`tenant_id`,`site_id`,`organization_id`,`created_at`),
            INDEX `idx_water_bet` (`related_bet_record_id`,`related_bet_detail_id`),
            INDEX `idx_water_issue` (`site_id`,`issue_no`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `organization_water_ledger`');
    }
}
