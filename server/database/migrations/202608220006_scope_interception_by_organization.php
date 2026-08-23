<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

final class ScopeInterceptionByOrganization extends Migrator
{
    public function up(): void
    {
        $columns=Db::query("SHOW COLUMNS FROM `agent_interceptions` LIKE 'organization_id'");
        if($columns===[])Db::execute('ALTER TABLE `agent_interceptions` ADD COLUMN `organization_id` BIGINT UNSIGNED NULL AFTER `site_id`, ADD INDEX `idx_agent_interception_org_issue` (`organization_id`,`lottery_id`,`issue_no`,`lottery_odds_id`,`number_key`)');
        Db::execute('UPDATE `agent_interceptions` i INNER JOIN `site_users` u ON u.id=i.user_id SET i.organization_id=u.organization_id WHERE i.organization_id IS NULL AND u.organization_id IS NOT NULL');
    }

    public function down(): void
    {
        $columns=Db::query("SHOW COLUMNS FROM `agent_interceptions` LIKE 'organization_id'");
        if($columns!==[])Db::execute('ALTER TABLE `agent_interceptions` DROP INDEX `idx_agent_interception_org_issue`, DROP COLUMN `organization_id`');
    }
}
