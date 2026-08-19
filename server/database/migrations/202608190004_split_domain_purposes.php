<?php
declare(strict_types=1);
use think\migration\Migrator;

final class SplitDomainPurposes extends Migrator
{
    public function up(): void
    {
        $this->execute("ALTER TABLE `domains` ADD COLUMN `domain_type` ENUM('agent','user') NOT NULL DEFAULT 'user' AFTER `domain`, ADD INDEX `idx_domain_site_type` (`site_id`,`domain_type`,`status`)");
        $this->execute("UPDATE `domains` SET `domain_type`='agent' WHERE `domain_type`='user'");
    }
    public function down(): void { $this->execute("ALTER TABLE `domains` DROP INDEX `idx_domain_site_type`, DROP COLUMN `domain_type`"); }
}
