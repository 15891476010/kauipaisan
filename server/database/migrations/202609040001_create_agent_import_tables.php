<?php
declare(strict_types=1);
use think\migration\Migrator;

final class CreateAgentImportTables extends Migrator
{
    public function change(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `agent_import_profiles` (
          `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `tenant_id` BIGINT UNSIGNED NOT NULL,
          `site_id` BIGINT UNSIGNED NOT NULL, `name` VARCHAR(120) NOT NULL, `base_url` VARCHAR(500) NOT NULL,
          `username` VARCHAR(120) NOT NULL, `password_cipher` TEXT NOT NULL, `enabled` TINYINT NOT NULL DEFAULT 1,
          `last_login_at` DATETIME NULL, `last_probe_at` DATETIME NULL, `last_probe_status` VARCHAR(30) NULL,
          `last_probe_error` VARCHAR(500) NULL, `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL,
          INDEX `idx_import_profile_scope` (`tenant_id`,`site_id`,`enabled`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->execute("CREATE TABLE IF NOT EXISTS `agent_import_batches` (
          `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `tenant_id` BIGINT UNSIGNED NOT NULL,
          `site_id` BIGINT UNSIGNED NOT NULL, `profile_id` BIGINT UNSIGNED NOT NULL, `target_organization_id` BIGINT UNSIGNED NOT NULL,
          `from_date` DATE NOT NULL, `to_date` DATE NOT NULL, `types` JSON NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
          `external_counts` JSON NULL, `created_counts` JSON NULL, `snapshot` JSON NULL, `error` TEXT NULL,
          `started_at` DATETIME NULL, `finished_at` DATETIME NULL, `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL,
          INDEX `idx_import_batch_scope` (`tenant_id`,`site_id`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->execute("CREATE TABLE IF NOT EXISTS `agent_import_records` (
          `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `batch_id` BIGINT UNSIGNED NOT NULL,
          `entity_type` VARCHAR(30) NOT NULL, `external_id` VARCHAR(120) NULL, `local_id` BIGINT UNSIGNED NULL,
          `action` VARCHAR(20) NOT NULL, `payload` JSON NULL, `created_at` DATETIME NOT NULL,
          INDEX `idx_import_record_batch` (`batch_id`,`entity_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
