<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

final class NormalizeLotteryOddsTree extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `lottery_odds_categories` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,`lottery_id` BIGINT UNSIGNED NOT NULL,`name` VARCHAR(80) NOT NULL,`status` TINYINT NOT NULL DEFAULT 1,`sort` INT NOT NULL DEFAULT 0,`created_at` DATETIME NULL,`updated_at` DATETIME NULL,`deleted_at` DATETIME NULL,UNIQUE KEY `uk_odds_category` (`lottery_id`,`name`),INDEX `idx_odds_category_tree` (`lottery_id`,`status`,`sort`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $odds = $this->table('lottery_odds');
        if (!$odds->hasColumn('category_id')) $odds->addColumn('category_id', 'biginteger', ['signed' => false, 'null' => true, 'after' => 'lottery_id'])->save();

        $now = date('Y-m-d H:i:s');
        $categories = Db::name('lottery_odds')->whereNull('deleted_at')->field('tenant_id,lottery_id,category,MIN(sort) AS sort')->group('tenant_id,lottery_id,category')->select()->toArray();
        foreach ($categories as $category) {
            $id = (int)Db::name('lottery_odds_categories')->where('lottery_id', (int)$category['lottery_id'])->where('name', (string)$category['category'])->value('id');
            if ($id < 1) $id = (int)Db::name('lottery_odds_categories')->insertGetId(['tenant_id' => (int)$category['tenant_id'], 'lottery_id' => (int)$category['lottery_id'], 'name' => (string)$category['category'], 'status' => 1, 'sort' => (int)$category['sort'], 'created_at' => $now, 'updated_at' => $now]);
            Db::name('lottery_odds')->where('lottery_id', (int)$category['lottery_id'])->where('category', (string)$category['category'])->update(['category_id' => $id]);
        }

        $this->execute("ALTER TABLE `lottery_odds` MODIFY `min_bet` DECIMAL(12,4) NULL DEFAULT NULL, MODIFY `odds_limit` DECIMAL(12,4) NULL DEFAULT NULL, MODIFY `single_bet_limit` DECIMAL(14,2) NULL DEFAULT NULL, MODIFY `single_item_limit` DECIMAL(14,2) NULL DEFAULT NULL, MODIFY `odds` DECIMAL(12,4) NULL DEFAULT NULL, MODIFY `offline_rebate` DECIMAL(10,4) NULL DEFAULT NULL");
        if (!$odds->hasIndex(['lottery_id', 'category_id', 'sort'])) $odds->addIndex(['lottery_id', 'category_id', 'sort'], ['name' => 'idx_odds_tree'])->save();
    }

    public function down(): void
    {
        Db::name('lottery_odds')->whereNull('min_bet')->update(['min_bet' => 0]);
        Db::name('lottery_odds')->whereNull('odds_limit')->update(['odds_limit' => 0]);
        Db::name('lottery_odds')->whereNull('single_bet_limit')->update(['single_bet_limit' => 0]);
        Db::name('lottery_odds')->whereNull('single_item_limit')->update(['single_item_limit' => 0]);
        Db::name('lottery_odds')->whereNull('odds')->update(['odds' => 0]);
        Db::name('lottery_odds')->whereNull('offline_rebate')->update(['offline_rebate' => 0]);
        $this->execute("ALTER TABLE `lottery_odds` MODIFY `min_bet` DECIMAL(12,4) NOT NULL DEFAULT 0, MODIFY `odds_limit` DECIMAL(12,4) NOT NULL DEFAULT 0, MODIFY `single_bet_limit` DECIMAL(14,2) NOT NULL DEFAULT 0, MODIFY `single_item_limit` DECIMAL(14,2) NOT NULL DEFAULT 0, MODIFY `odds` DECIMAL(12,4) NOT NULL DEFAULT 0, MODIFY `offline_rebate` DECIMAL(10,4) NOT NULL DEFAULT 0");
        $odds = $this->table('lottery_odds');
        if ($odds->hasIndex(['lottery_id', 'category_id', 'sort'])) $odds->removeIndexByName('idx_odds_tree')->save();
        if ($odds->hasColumn('category_id')) $odds->removeColumn('category_id')->save();
        $this->execute('DROP TABLE IF EXISTS `lottery_odds_categories`');
    }
}
