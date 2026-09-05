<?php
declare(strict_types=1);

use think\migration\Migrator;

final class AddDailyScoreUsageDate extends Migrator
{
    public function up(): void
    {
        $users = $this->table('site_users');
        if (!$users->hasColumn('used_balance_date')) {
            $users->addColumn('used_balance_date', 'date', ['null' => true, 'after' => 'used_balance'])->save();
        }
        $today = date('Y-m-d');
        $this->execute("UPDATE site_users SET used_balance_date='{$today}' WHERE used_balance_date IS NULL");
    }

    public function down(): void
    {
        $users = $this->table('site_users');
        if ($users->hasColumn('used_balance_date')) $users->removeColumn('used_balance_date')->save();
    }
}
