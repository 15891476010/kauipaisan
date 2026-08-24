<?php
declare(strict_types=1);

use think\migration\Migrator;

/** Keep a denormalized last-login IP/location on every login-capable account. */
final class AddLastLoginLocation extends Migrator
{
    private const TABLES = ['admins', 'site_admins', 'site_users', 'agent_admins', 'agent_subaccounts', 'organization_accounts', 'sites', 'users'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            $table = $this->table($tableName);
            if (!$table->hasColumn('last_login_ip')) $table->addColumn('last_login_ip', 'string', ['limit' => 45, 'null' => true]);
            if (!$table->hasColumn('last_login_location')) $table->addColumn('last_login_location', 'string', ['limit' => 255, 'null' => true]);
            $table->save();
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            $table = $this->table($tableName);
            if ($table->hasColumn('last_login_location')) $table->removeColumn('last_login_location');
            if ($table->hasColumn('last_login_ip')) $table->removeColumn('last_login_ip');
            $table->save();
        }
    }
}
