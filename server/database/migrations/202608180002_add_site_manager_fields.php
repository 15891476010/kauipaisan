<?php
declare(strict_types=1);
use think\migration\Migrator;

final class AddSiteManagerFields extends Migrator
{
    public function up(): void
    {
        $this->execute("ALTER TABLE `sites` ADD COLUMN `manager_username` VARCHAR(80) NULL AFTER `code`, ADD COLUMN `manager_password` VARCHAR(255) NULL AFTER `manager_username`, ADD COLUMN `manager_phone` VARCHAR(30) NULL AFTER `manager_password`");
    }
    public function down(): void
    {
        $this->execute("ALTER TABLE `sites` DROP COLUMN `manager_phone`, DROP COLUMN `manager_password`, DROP COLUMN `manager_username`");
    }
}
