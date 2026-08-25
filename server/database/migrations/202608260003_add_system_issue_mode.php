<?php
declare(strict_types=1);

use think\migration\Migrator;

final class AddSystemIssueMode extends Migrator
{
    public function up(): void
    {
        if (!$this->table('lotteries')->hasColumn('system_issue_mode')) $this->execute("ALTER TABLE `lotteries` ADD COLUMN `system_issue_mode` VARCHAR(12) NOT NULL DEFAULT 'auto' AFTER `system_interval_seconds`");
    }

    public function down(): void
    {
        if ($this->table('lotteries')->hasColumn('system_issue_mode')) $this->execute("ALTER TABLE `lotteries` DROP COLUMN `system_issue_mode`");
    }
}
