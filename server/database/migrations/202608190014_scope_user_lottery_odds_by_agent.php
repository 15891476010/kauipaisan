<?php
declare(strict_types=1);

use think\migration\Migrator;

final class ScopeUserLotteryOddsByAgent extends Migrator
{
    public function up(): void
    {
        $table = $this->table('user_lottery_odds');
        if (!$table->hasColumn('agent_id')) {
            $table->addColumn('agent_id', 'biginteger', ['signed' => false, 'default' => 0, 'after' => 'site_id'])->save();
        }

        $this->execute('UPDATE `user_lottery_odds` uo INNER JOIN `sites` s ON s.id = uo.site_id SET uo.agent_id = s.agent_id WHERE uo.agent_id = 0');
        if (!$table->hasIndex(['agent_id', 'user_id'])) {
            $table->addIndex(['agent_id', 'user_id'], ['name' => 'idx_user_odds_agent_user'])->save();
        }
    }

    public function down(): void
    {
        $table = $this->table('user_lottery_odds');
        if ($table->hasIndex(['agent_id', 'user_id'])) $table->removeIndexByName('idx_user_odds_agent_user')->save();
        if ($table->hasColumn('agent_id')) $table->removeColumn('agent_id')->save();
    }
}
