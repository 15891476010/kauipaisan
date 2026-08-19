<?php
declare(strict_types=1);
use think\migration\Migrator;
final class MakeSiteCodeOptional extends Migrator
{
    public function up(): void { $this->execute("ALTER TABLE `sites` MODIFY COLUMN `code` VARCHAR(64) NULL"); }
    public function down(): void { $this->execute("ALTER TABLE `sites` MODIFY COLUMN `code` VARCHAR(64) NOT NULL"); }
}
