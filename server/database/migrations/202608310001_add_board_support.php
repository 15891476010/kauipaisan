<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

/**
 * Introduce an extensible盘口 dimension. Existing data is assigned to A盘;
 * adding another盘 later only requires inserting a board row and duplicating
 * the relevant odds/configuration rows.
 */
final class AddBoardSupport extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `lottery_boards` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,`code` VARCHAR(8) NOT NULL,`name` VARCHAR(40) NOT NULL,`status` TINYINT NOT NULL DEFAULT 1,`sort` INT NOT NULL DEFAULT 0,`settings` JSON NULL,`created_at` DATETIME NOT NULL,`updated_at` DATETIME NOT NULL,UNIQUE KEY `uk_lottery_board_tenant_code` (`tenant_id`,`code`),INDEX `idx_lottery_board_status` (`tenant_id`,`status`,`sort`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $now = date('Y-m-d H:i:s');
        $tenants = Db::name('lotteries')->whereNull('deleted_at')->distinct(true)->column('tenant_id');
        foreach ($tenants as $tenantId) {
            if (!Db::name('lottery_boards')->where('tenant_id',(int)$tenantId)->where('code','A')->find()) Db::name('lottery_boards')->insert([
                'tenant_id' => (int)$tenantId,
                'code' => 'A',
                'name' => 'A盘',
                'status' => 1,
                'sort' => 1,
                'settings' => json_encode(['default' => true], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->addBoardColumn('lotteries', 'default_board_code', 'VARCHAR(8) NOT NULL DEFAULT \'A\' AFTER `code`');
        foreach (['lottery_odds','lottery_odds_categories','user_lottery_odds','bet_records','bet_submissions','bet_details','user_stop_drops','agent_interceptions','interception_capacity_usage'] as $table) {
            $this->addBoardColumn($table, 'board_code', 'VARCHAR(8) NOT NULL DEFAULT \'A\'');
        }

        // Allow identical play names to exist under different盘口s.
        $this->dropIndexIfPresent('lottery_odds_categories', 'uk_odds_category');
        $this->dropIndexIfPresent('lottery_odds', 'uk_odds_lottery_code');
        $this->dropIndexIfPresent('user_lottery_odds', 'uk_user_lottery_odds');
        $this->dropIndexIfPresent('interception_capacity_usage', 'uk_interception_capacity');
        $this->execute("ALTER TABLE `lottery_odds_categories` ADD UNIQUE KEY `uk_odds_category_board` (`lottery_id`,`board_code`,`name`)");
        $this->execute("ALTER TABLE `lottery_odds` ADD UNIQUE KEY `uk_odds_lottery_board_code` (`lottery_id`,`board_code`,`category`,`name`)");
        $this->execute("ALTER TABLE `user_lottery_odds` ADD UNIQUE KEY `uk_user_lottery_odds_board` (`user_id`,`lottery_odds_id`,`board_code`)");
        $this->execute("ALTER TABLE `interception_capacity_usage` ADD UNIQUE KEY `uk_interception_capacity_board` (`tenant_id`,`scope_type`,`scope_id`,`lottery_id`,`board_code`,`lottery_odds_id`,`issue_no`,`number_key`)");

        // Explicitly normalize any rows created by older deployments.
        foreach (['lottery_odds','lottery_odds_categories','user_lottery_odds','bet_records','bet_submissions','bet_details','user_stop_drops','agent_interceptions','interception_capacity_usage'] as $table) {
            $this->execute("UPDATE `{$table}` SET `board_code`='A' WHERE `board_code` IS NULL OR `board_code`=''");
        }
    }

    private function addBoardColumn(string $table, string $column, string $definition): void
    {
        $schema = $this->table($table);
        if (!$schema->hasColumn($column)) $this->execute("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }

    private function dropIndexIfPresent(string $table, string $index): void
    {
        $rows = Db::query("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]);
        if ($rows !== []) $this->execute("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
    }

    public function down(): void
    {
        foreach (['lottery_odds_categories'=>'uk_odds_category_board','lottery_odds'=>'uk_odds_lottery_board_code','user_lottery_odds'=>'uk_user_lottery_odds_board','interception_capacity_usage'=>'uk_interception_capacity_board'] as $table=>$index) $this->dropIndexIfPresent($table, $index);
        $this->execute("ALTER TABLE `lottery_odds_categories` ADD UNIQUE KEY `uk_odds_category` (`lottery_id`,`name`)");
        $this->execute("ALTER TABLE `lottery_odds` ADD UNIQUE KEY `uk_odds_lottery_code` (`lottery_id`,`category`,`name`)");
        $this->execute("ALTER TABLE `user_lottery_odds` ADD UNIQUE KEY `uk_user_lottery_odds` (`user_id`,`lottery_odds_id`)");
        $this->execute("ALTER TABLE `interception_capacity_usage` ADD UNIQUE KEY `uk_interception_capacity` (`tenant_id`,`scope_type`,`scope_id`,`lottery_id`,`lottery_odds_id`,`issue_no`,`number_key`)");
        foreach (['lottery_odds','lottery_odds_categories','user_lottery_odds','bet_records','bet_submissions','bet_details','user_stop_drops','agent_interceptions','interception_capacity_usage'] as $table) {
            if ($this->table($table)->hasColumn('board_code')) $this->execute("ALTER TABLE `{$table}` DROP COLUMN `board_code`");
        }
        if ($this->table('lotteries')->hasColumn('default_board_code')) $this->execute("ALTER TABLE `lotteries` DROP COLUMN `default_board_code`");
        $this->execute('DROP TABLE IF EXISTS `lottery_boards`');
    }
}
