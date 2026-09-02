<?php
declare(strict_types=1);

use think\migration\Migrator;

final class AddRobotSkipWindows extends Migrator
{
    public function up(): void
    {
        $table=$this->table('robot_accounts');
        if(!$table->hasColumn('skip_windows'))$this->execute("ALTER TABLE `robot_accounts` ADD COLUMN `skip_windows` JSON NULL AFTER `lottery_configs`");
        if(!$table->hasColumn('win_weight'))$this->execute("ALTER TABLE `robot_accounts` ADD COLUMN `win_weight` DECIMAL(5,2) NOT NULL DEFAULT 50.00 AFTER `skip_windows`");
    }

    public function down(): void
    {
        if($this->table('robot_accounts')->hasColumn('skip_windows'))$this->execute("ALTER TABLE `robot_accounts` DROP COLUMN `skip_windows`");
        if($this->table('robot_accounts')->hasColumn('win_weight'))$this->execute("ALTER TABLE `robot_accounts` DROP COLUMN `win_weight`");
    }
}
