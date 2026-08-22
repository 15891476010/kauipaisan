<?php
declare(strict_types=1);

use think\migration\Migrator;

final class AddOrganizationScopeToAuditLogs extends Migrator
{
    public function up(): void
    {
        $table=$this->table('audit_logs');
        if(!$table->hasColumn('organization_id'))$table->addColumn('organization_id','biginteger',['signed'=>false,'null'=>true,'after'=>'agent_id'])->addIndex(['organization_id','created_at'],['name'=>'idx_audit_organization'])->save();
        $subaccounts=$this->table('agent_subaccounts');
        if(!$subaccounts->hasColumn('organization_id'))$subaccounts->addColumn('organization_id','biginteger',['signed'=>false,'null'=>true,'after'=>'site_id'])->addIndex(['site_id','organization_id'],['name'=>'idx_subaccount_organization'])->save();
    }

    public function down(): void
    {
        $table=$this->table('audit_logs');
        if($table->hasColumn('organization_id'))$table->removeColumn('organization_id')->save();
        $subaccounts=$this->table('agent_subaccounts');
        if($subaccounts->hasColumn('organization_id'))$subaccounts->removeColumn('organization_id')->save();
    }
}
