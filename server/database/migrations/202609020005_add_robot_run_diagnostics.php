<?php
declare(strict_types=1);

use think\migration\Migrator;

final class AddRobotRunDiagnostics extends Migrator
{
    public function up(): void
    {
        $table = $this->table('robot_accounts');
        if (!$table->hasColumn('last_run_at')) $this->execute("ALTER TABLE `robot_accounts` ADD COLUMN `last_run_at` DATETIME NULL AFTER `last_bet_at`");
        if (!$table->hasColumn('last_run_status')) $this->execute("ALTER TABLE `robot_accounts` ADD COLUMN `last_run_status` VARCHAR(16) NULL AFTER `last_run_at`");
        if (!$table->hasColumn('last_run_message')) $this->execute("ALTER TABLE `robot_accounts` ADD COLUMN `last_run_message` VARCHAR(255) NULL AFTER `last_run_status`");
    }

    public function down(): void
    {
        $table = $this->table('robot_accounts');
        foreach (['last_run_message', 'last_run_status', 'last_run_at'] as $column) {
            if ($table->hasColumn($column)) $this->execute("ALTER TABLE `robot_accounts` DROP COLUMN `{$column}`");
        }
    }
}
