<?php
declare(strict_types=1);
use think\migration\Migrator;
final class AllowPlayableOddsCategories extends Migrator
{
    public function up(): void
    {
        $table=$this->table('lottery_odds_categories');
        if(!$table->hasColumn('is_playable')) $table->addColumn('is_playable','boolean',['default'=>false,'after'=>'name'])->save();
        $this->execute("ALTER TABLE `lottery_odds_categories` ADD COLUMN `min_bet` DECIMAL(12,4) NULL DEFAULT NULL AFTER `is_playable`, ADD COLUMN `odds_limit` DECIMAL(12,4) NULL DEFAULT NULL AFTER `min_bet`, ADD COLUMN `single_bet_limit` DECIMAL(14,2) NULL DEFAULT NULL AFTER `odds_limit`, ADD COLUMN `single_item_limit` DECIMAL(14,2) NULL DEFAULT NULL AFTER `single_bet_limit`, ADD COLUMN `odds` DECIMAL(12,4) NULL DEFAULT NULL AFTER `single_item_limit`, ADD COLUMN `offline_rebate` DECIMAL(10,4) NULL DEFAULT NULL AFTER `odds`");
    }
    public function down(): void { $this->execute("ALTER TABLE `lottery_odds_categories` DROP COLUMN `offline_rebate`, DROP COLUMN `odds`, DROP COLUMN `single_item_limit`, DROP COLUMN `single_bet_limit`, DROP COLUMN `odds_limit`, DROP COLUMN `min_bet`, DROP COLUMN `is_playable`"); }
}
