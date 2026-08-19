<?php
declare(strict_types=1);

use think\migration\Migrator;

final class AddSiteScopeToSettings extends Migrator
{
    public function up(): void
    {
        $this->execute("ALTER TABLE `settings` ADD COLUMN `site_id` BIGINT UNSIGNED NULL AFTER `tenant_id`, DROP INDEX `uk_setting_scope_key`, ADD UNIQUE KEY `uk_setting_site_key` (`tenant_id`, `site_id`, `key`)");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE `settings` DROP INDEX `uk_setting_site_key`, DROP COLUMN `site_id`, ADD UNIQUE KEY `uk_setting_scope_key` (`tenant_id`, `key`)");
    }
}
