<?php
declare(strict_types=1);
use think\migration\Migrator;

final class CreateUserQuickEntryTables extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `user_quick_tags` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `tenant_id` BIGINT UNSIGNED NOT NULL, `site_id` BIGINT UNSIGNED NOT NULL, `user_id` BIGINT UNSIGNED NOT NULL, `name` VARCHAR(40) NOT NULL, `created_at` DATETIME NOT NULL, UNIQUE KEY `uk_quick_tag_user_name` (`site_id`,`user_id`,`name`), INDEX `idx_quick_tag_user` (`site_id`,`user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->execute("CREATE TABLE IF NOT EXISTS `user_quick_preferences` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `tenant_id` BIGINT UNSIGNED NOT NULL, `site_id` BIGINT UNSIGNED NOT NULL, `user_id` BIGINT UNSIGNED NOT NULL, `preferences` JSON NOT NULL, `updated_at` DATETIME NOT NULL, UNIQUE KEY `uk_quick_pref_user` (`site_id`,`user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `user_quick_preferences`');
        $this->execute('DROP TABLE IF EXISTS `user_quick_tags`');
    }
}
