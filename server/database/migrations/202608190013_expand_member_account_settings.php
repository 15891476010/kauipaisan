<?php
declare(strict_types=1);
use think\migration\Migrator;

final class ExpandMemberAccountSettings extends Migrator
{
    public function up(): void
    {
        $users=$this->table('site_users');
        if (!$users->hasColumn('remark')) $users->addColumn('remark','string',['limit'=>255,'null'=>true,'after'=>'display_name'])->save();
        if (!$users->hasColumn('account_state')) $users->addColumn('account_state','string',['limit'=>20,'default'=>'enabled','after'=>'status'])->save();
        if (!$users->hasColumn('interception_rate')) $users->addColumn('interception_rate','decimal',['precision'=>8,'scale'=>4,'default'=>0,'after'=>'used_balance'])->save();
        $permissions=$this->table('user_lottery_permissions');
        if (!$permissions->hasColumn('offline_rebate')) $permissions->addColumn('offline_rebate','decimal',['precision'=>10,'scale'=>4,'default'=>0,'after'=>'can_bet'])->save();
        $this->execute("CREATE TABLE IF NOT EXISTS `user_lottery_odds` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,`site_id` BIGINT UNSIGNED NOT NULL,`user_id` BIGINT UNSIGNED NOT NULL,`lottery_id` BIGINT UNSIGNED NOT NULL,`lottery_odds_id` BIGINT UNSIGNED NOT NULL,`min_bet` DECIMAL(12,4) NOT NULL,`odds_limit` DECIMAL(12,4) NOT NULL,`single_bet_limit` DECIMAL(14,2) NOT NULL,`single_item_limit` DECIMAL(14,2) NOT NULL,`odds` DECIMAL(12,4) NOT NULL,`offline_rebate` DECIMAL(10,4) NOT NULL DEFAULT 0,`created_at` DATETIME NOT NULL,`updated_at` DATETIME NOT NULL,UNIQUE KEY `uk_user_lottery_odds` (`user_id`,`lottery_odds_id`),INDEX `idx_user_odds_site_user` (`site_id`,`user_id`),INDEX `idx_user_odds_lottery` (`lottery_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `user_lottery_odds`');
    }
}
