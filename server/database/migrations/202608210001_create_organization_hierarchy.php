<?php
declare(strict_types=1);

use think\migration\Migrator;

final class CreateOrganizationHierarchy extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `organization_nodes` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL,`site_id` BIGINT UNSIGNED NOT NULL,`parent_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,`level` VARCHAR(24) NOT NULL,`depth` TINYINT UNSIGNED NOT NULL DEFAULT 1,`path` VARCHAR(800) NOT NULL DEFAULT '',`name` VARCHAR(120) NOT NULL,`code` VARCHAR(64) NOT NULL,`credit_limit` DECIMAL(18,2) NOT NULL DEFAULT 0,`permissions` JSON NULL,`settings` JSON NULL,`status` TINYINT NOT NULL DEFAULT 1,`created_at` DATETIME NOT NULL,`updated_at` DATETIME NOT NULL,`deleted_at` DATETIME NULL,UNIQUE KEY `uk_org_site_code` (`site_id`,`code`),INDEX `idx_org_parent` (`site_id`,`parent_id`,`status`,`deleted_at`),INDEX `idx_org_level` (`site_id`,`level`,`status`,`deleted_at`),INDEX `idx_org_path` (`site_id`,`path`(191))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->execute("CREATE TABLE IF NOT EXISTS `organization_accounts` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL,`site_id` BIGINT UNSIGNED NOT NULL,`organization_id` BIGINT UNSIGNED NOT NULL,`username` VARCHAR(80) NOT NULL,`display_name` VARCHAR(120) NOT NULL,`phone` VARCHAR(30) NULL,`password` VARCHAR(255) NOT NULL,`permissions` JSON NULL,`status` TINYINT NOT NULL DEFAULT 1,`last_login_at` DATETIME NULL,`created_at` DATETIME NOT NULL,`updated_at` DATETIME NOT NULL,`deleted_at` DATETIME NULL,UNIQUE KEY `uk_org_account_site_username` (`site_id`,`username`),INDEX `idx_org_account_login` (`username`,`status`,`deleted_at`),INDEX `idx_org_account_scope` (`site_id`,`organization_id`,`deleted_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $users=$this->table('site_users');
        if (!$users->hasColumn('organization_id')) $users->addColumn('organization_id','biginteger',['signed'=>false,'null'=>true,'after'=>'site_id'])->addIndex(['site_id','organization_id'],['name'=>'idx_site_user_organization'])->save();
        $sessions=$this->table('account_sessions');
        if (!$sessions->hasColumn('organization_id')) $sessions->addColumn('organization_id','biginteger',['signed'=>false,'null'=>true,'after'=>'site_id'])->addIndex(['organization_id','account_type','last_seen_at'],['name'=>'idx_account_session_organization'])->save();

        $now=date('Y-m-d H:i:s');
        $this->execute("INSERT IGNORE INTO `organization_nodes` (`tenant_id`,`site_id`,`parent_id`,`level`,`depth`,`path`,`name`,`code`,`credit_limit`,`permissions`,`settings`,`status`,`created_at`,`updated_at`) SELECT s.tenant_id,s.id,0,'director',1,'',CONCAT(s.name,' · 根总监'),CONCAT('DIR-',s.id),COALESCE(JSON_EXTRACT(s.settings,'$.credit_limit'),0),JSON_ARRAY('*'),JSON_OBJECT(),s.status,'{$now}','{$now}' FROM sites s WHERE s.deleted_at IS NULL");
        $this->execute("UPDATE organization_nodes SET path=CONCAT('/',id,'/') WHERE parent_id=0 AND path=''");
        $this->execute("INSERT IGNORE INTO `organization_accounts` (`tenant_id`,`site_id`,`organization_id`,`username`,`display_name`,`phone`,`password`,`permissions`,`status`,`last_login_at`,`created_at`,`updated_at`) SELECT a.tenant_id,a.site_id,n.id,a.username,a.display_name,a.phone,a.password,JSON_ARRAY('*'),a.status,a.last_login_at,COALESCE(a.created_at,'{$now}'),COALESCE(a.updated_at,'{$now}') FROM site_admins a INNER JOIN organization_nodes n ON n.site_id=a.site_id AND n.level='director' AND n.parent_id=0 AND n.deleted_at IS NULL WHERE a.deleted_at IS NULL");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `organization_accounts`');
        $this->execute('DROP TABLE IF EXISTS `organization_nodes`');
    }
}
