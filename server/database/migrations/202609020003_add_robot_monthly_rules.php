<?php
declare(strict_types=1);

use think\migration\Migrator;

final class AddRobotMonthlyRules extends Migrator
{
    public function up(): void
    {
        if(!$this->table('robot_accounts')->hasColumn('monthly_rules'))$this->execute("ALTER TABLE `robot_accounts` ADD COLUMN `monthly_rules` JSON NULL AFTER `win_weight`");
    }

    public function down(): void
    {
        if($this->table('robot_accounts')->hasColumn('monthly_rules'))$this->execute("ALTER TABLE `robot_accounts` DROP COLUMN `monthly_rules`");
    }
}
