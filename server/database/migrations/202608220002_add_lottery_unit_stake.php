<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddLotteryUnitStake extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->table('lotteries')->hasColumn('unit_stake')) {
            $this->execute("ALTER TABLE `lotteries` ADD COLUMN `unit_stake` DECIMAL(10,2) NOT NULL DEFAULT 2.00 AFTER `code`");
        }
    }

    public function down(): void
    {
        if ($this->table('lotteries')->hasColumn('unit_stake')) {
            $this->execute("ALTER TABLE `lotteries` DROP COLUMN `unit_stake`");
        }
    }
}
