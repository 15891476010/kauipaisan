<?php
declare(strict_types=1);

use think\migration\Migrator;

final class AddRobotHourlyVariety extends Migrator
{
    public function up(): void
    {
        $table=$this->table('robot_accounts');
        if(!$table->hasColumn('hourly_weights'))$this->execute("ALTER TABLE `robot_accounts` ADD COLUMN `hourly_weights` JSON NULL AFTER `monthly_rules`");
        if(!$table->hasColumn('last_play_key'))$this->execute("ALTER TABLE `robot_accounts` ADD COLUMN `last_play_key` VARCHAR(40) NULL AFTER `hourly_weights`");
        if(!$table->hasColumn('play_repeat_count'))$this->execute("ALTER TABLE `robot_accounts` ADD COLUMN `play_repeat_count` INT NOT NULL DEFAULT 0 AFTER `last_play_key`");
    }
    public function down(): void
    {
        $table=$this->table('robot_accounts');
        if($table->hasColumn('play_repeat_count'))$this->execute("ALTER TABLE `robot_accounts` DROP COLUMN `play_repeat_count`");
        if($table->hasColumn('last_play_key'))$this->execute("ALTER TABLE `robot_accounts` DROP COLUMN `last_play_key`");
        if($table->hasColumn('hourly_weights'))$this->execute("ALTER TABLE `robot_accounts` DROP COLUMN `hourly_weights`");
    }
}
