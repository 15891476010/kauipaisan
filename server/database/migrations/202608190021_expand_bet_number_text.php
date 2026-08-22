<?php
declare(strict_types=1);

use think\migration\Migrator;

final class ExpandBetNumberText extends Migrator
{
    public function up(): void
    {
        $this->execute("ALTER TABLE `bet_details` MODIFY `number_text` TEXT NOT NULL");
        $this->execute("ALTER TABLE `user_stop_drops` MODIFY `number_text` TEXT NOT NULL");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE `bet_details` MODIFY `number_text` VARCHAR(255) NOT NULL");
        $this->execute("ALTER TABLE `user_stop_drops` MODIFY `number_text` VARCHAR(255) NOT NULL");
    }
}
