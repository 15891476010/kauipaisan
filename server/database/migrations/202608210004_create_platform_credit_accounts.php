<?php
declare(strict_types=1);

use think\migration\Migrator;

final class CreatePlatformCreditAccounts extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `platform_credit_accounts` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL,`balance` DECIMAL(18,2) NOT NULL DEFAULT 0,`created_at` DATETIME NOT NULL,`updated_at` DATETIME NOT NULL,UNIQUE KEY `uk_platform_credit_tenant` (`tenant_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `platform_credit_accounts`');
    }
}
