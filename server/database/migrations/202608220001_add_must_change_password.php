<?php
declare(strict_types=1);

use think\migration\Migrator;

final class AddMustChangePassword extends Migrator
{
    public function up(): void
    {
        foreach (['site_users', 'agent_subaccounts', 'organization_accounts'] as $tableName) {
            $table = $this->table($tableName);
            if (!$table->hasColumn('must_change_password')) {
                $table->addColumn('must_change_password', 'boolean', ['default'=>0, 'after'=>'password'])->save();
            }
        }
    }

    public function down(): void
    {
        foreach (['site_users', 'agent_subaccounts', 'organization_accounts'] as $tableName) {
            $table = $this->table($tableName);
            if ($table->hasColumn('must_change_password')) $table->removeColumn('must_change_password')->save();
        }
    }
}
