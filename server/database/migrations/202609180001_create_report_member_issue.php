<?php
declare(strict_types=1);
use think\migration\Migrator;

final class CreateReportMemberIssue extends Migrator
{
    public function change(): void
    {
        // Materialized report rows: one row per (member, issue, lottery,
        // settled) group pre-computed from bet_details + ledger +
        // interceptions. Report reads aggregate these few thousand rows
        // instead of scanning hundreds of thousands of detail rows.
        $this->execute("CREATE TABLE IF NOT EXISTS `report_member_issue` (
          `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `tenant_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
          `site_id` BIGINT UNSIGNED NOT NULL,
          `user_id` BIGINT UNSIGNED NOT NULL,
          `issue_no` VARCHAR(32) NOT NULL,
          `lottery_name` VARCHAR(32) NOT NULL DEFAULT '',
          `day` DATE NOT NULL,
          `settled` TINYINT NOT NULL DEFAULT 0,
          `detail_count` INT NOT NULL DEFAULT 0,
          `number_count` INT NOT NULL DEFAULT 0,
          `amount` DECIMAL(20,2) NOT NULL DEFAULT 0,
          `win_amount` DECIMAL(20,2) NOT NULL DEFAULT 0,
          `rebate` DECIMAL(20,2) NOT NULL DEFAULT 0,
          `intercepted` DECIMAL(20,2) NOT NULL DEFAULT 0,
          `placed_at` DATETIME NULL,
          `ledger_json` MEDIUMTEXT NULL,
          `updated_at` DATETIME NOT NULL,
          UNIQUE KEY `uq_report_mi` (`site_id`,`user_id`,`issue_no`,`lottery_name`,`settled`,`day`),
          KEY `idx_report_day` (`site_id`,`day`),
          KEY `idx_report_user_day` (`site_id`,`user_id`,`day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Incremental refresh watermarks per site.
        $this->execute("CREATE TABLE IF NOT EXISTS `report_materialize_state` (
          `site_id` BIGINT UNSIGNED NOT NULL,
          `last_bet_record_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
          `last_ledger_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
          `last_interception_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
          `last_stop_drop_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
          `updated_at` DATETIME NOT NULL,
          PRIMARY KEY (`site_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
