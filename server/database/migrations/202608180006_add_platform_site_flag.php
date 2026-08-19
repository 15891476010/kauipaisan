<?php
declare(strict_types=1);

use think\migration\Migrator;

final class AddPlatformSiteFlag extends Migrator
{
    public function up(): void
    {
        $this->execute("ALTER TABLE `sites` ADD COLUMN `is_platform_site` TINYINT NOT NULL DEFAULT 0 AFTER `agent_id`, ADD INDEX `idx_site_platform` (`tenant_id`, `is_platform_site`, `deleted_at`)");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE `sites` DROP INDEX `idx_site_platform`, DROP COLUMN `is_platform_site`");
    }
}
