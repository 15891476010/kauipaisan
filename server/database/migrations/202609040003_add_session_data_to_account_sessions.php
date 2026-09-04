<?php
declare(strict_types=1);

use think\migration\Migrator;

final class AddSessionDataToAccountSessions extends Migrator
{
    public function up(): void
    {
        $table = $this->table('account_sessions');
        if (!$table->hasColumn('session_data')) $table->addColumn('session_data', 'text', ['null' => true, 'after' => 'user_agent'])->save();
    }
    public function down(): void
    {
        $table = $this->table('account_sessions');
        if ($table->hasColumn('session_data')) $table->removeColumn('session_data')->save();
    }
}
