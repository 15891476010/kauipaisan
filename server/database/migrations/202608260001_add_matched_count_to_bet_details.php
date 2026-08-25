<?php
declare(strict_types=1);

use think\migration\Migrator;

final class AddMatchedCountToBetDetails extends Migrator
{
    public function up(): void
    {
        $this->execute("ALTER TABLE `bet_details` ADD COLUMN `matched_count` INT NULL DEFAULT NULL AFTER `win_amount`");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE `bet_details` DROP COLUMN `matched_count`");
    }
}
