<?php
declare(strict_types=1);

use think\migration\Migrator;

final class AddRobotWinWeight extends Migrator
{
    public function up(): void
    {
        $table=$this->table('robot_accounts');
        if(!$table->hasColumn('win_weight'))$this->execute("ALTER TABLE `robot_accounts` ADD COLUMN `win_weight` DECIMAL(5,2) NOT NULL DEFAULT 50.00 AFTER `skip_windows`");
    }

    public function down(): void
    {
        if($this->table('robot_accounts')->hasColumn('win_weight'))$this->execute("ALTER TABLE `robot_accounts` DROP COLUMN `win_weight`");
    }
}
