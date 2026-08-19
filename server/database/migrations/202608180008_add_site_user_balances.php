<?php
declare(strict_types=1);

use think\migration\Migrator;

final class AddSiteUserBalances extends Migrator
{
    public function up(): void
    {
        $this->execute("ALTER TABLE `site_users` ADD COLUMN `balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `phone`, ADD COLUMN `credit_balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `balance`, ADD COLUMN `used_balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `credit_balance`");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE `site_users` DROP COLUMN `used_balance`, DROP COLUMN `credit_balance`, DROP COLUMN `balance`");
    }
}
