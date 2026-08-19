<?php
declare(strict_types=1);
use think\migration\Migrator;
final class AddUserSiteScope extends Migrator
{
    public function up(): void { $this->execute("ALTER TABLE `users` ADD COLUMN `site_id` BIGINT UNSIGNED NULL AFTER `agent_id`, ADD INDEX `idx_user_site` (`site_id`,`username`,`user_type`)"); }
    public function down(): void { $this->execute("ALTER TABLE `users` DROP INDEX `idx_user_site`, DROP COLUMN `site_id`"); }
}
