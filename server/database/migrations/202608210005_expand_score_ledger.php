<?php
declare(strict_types=1);

use think\migration\Migrator;

final class ExpandScoreLedger extends Migrator
{
    public function up(): void
    {
        $platform=$this->table('platform_credit_accounts');
        if(!$platform->hasColumn('total_score'))$platform->addColumn('total_score','decimal',['precision'=>18,'scale'=>2,'default'=>'0.00','after'=>'tenant_id'])->save();
        $ledger=$this->table('organization_credit_ledger');
        if(!$ledger->hasColumn('transaction_no'))$ledger->addColumn('transaction_no','string',['limit'=>48,'null'=>true,'after'=>'id'])->save();
        if(!$ledger->hasColumn('category'))$ledger->addColumn('category','string',['limit'=>40,'default'=>'other','after'=>'source_type'])->save();
        if(!$ledger->hasColumn('operator_type'))$ledger->addColumn('operator_type','string',['limit'=>24,'null'=>true,'after'=>'category'])->save();
        if(!$ledger->hasColumn('operator_id'))$ledger->addColumn('operator_id','biginteger',['signed'=>false,'null'=>true,'after'=>'operator_type'])->save();
        if(!$ledger->hasColumn('operator_name'))$ledger->addColumn('operator_name','string',['limit'=>80,'null'=>true,'after'=>'operator_id'])->save();
        if(!$ledger->hasColumn('counterparty_account_type'))$ledger->addColumn('counterparty_account_type','string',['limit'=>24,'null'=>true,'after'=>'operator_name'])->save();
        if(!$ledger->hasColumn('counterparty_account_id'))$ledger->addColumn('counterparty_account_id','biginteger',['signed'=>false,'null'=>true,'after'=>'counterparty_account_type'])->save();
        if(!$ledger->hasColumn('note'))$ledger->addColumn('note','string',['limit'=>500,'null'=>true,'after'=>'counterparty_account_id'])->save();
        if(!$ledger->hasColumn('metadata'))$ledger->addColumn('metadata','json',['null'=>true,'after'=>'note'])->save();
        $this->execute("UPDATE organization_credit_ledger SET transaction_no=CONCAT('LEGACY-',id) WHERE transaction_no IS NULL OR transaction_no=''");
        try{$this->execute('ALTER TABLE organization_credit_ledger MODIFY transaction_no VARCHAR(48) NOT NULL, ADD UNIQUE KEY uk_score_ledger_transaction_account (transaction_no,account_type,account_id,direction), ADD INDEX idx_score_ledger_search (tenant_id,site_id,source_type,created_at)');}catch(\Throwable $e){}
        $now=date('Y-m-d H:i:s');
        $this->execute("INSERT IGNORE INTO platform_credit_accounts(tenant_id,total_score,balance,created_at,updated_at) SELECT id,0,0,'{$now}','{$now}' FROM tenants");
        $this->execute("UPDATE platform_credit_accounts p SET total_score=(SELECT COALESCE(SUM(n.credit_limit),0) FROM organization_nodes n WHERE n.tenant_id=p.tenant_id AND n.parent_id=0 AND n.deleted_at IS NULL), balance=0");
        $this->execute("CREATE TEMPORARY TABLE tmp_org_allocations AS SELECT n.id,CASE WHEN n.level='agent' THEN COALESCE(u.allocated,0) ELSE COALESCE(c.allocated,0) END allocated FROM organization_nodes n LEFT JOIN (SELECT organization_id,SUM(credit_balance) allocated FROM site_users WHERE deleted_at IS NULL GROUP BY organization_id) u ON u.organization_id=n.id LEFT JOIN (SELECT parent_id,SUM(credit_limit) allocated FROM organization_nodes WHERE deleted_at IS NULL GROUP BY parent_id) c ON c.parent_id=n.id WHERE n.deleted_at IS NULL");
        $this->execute("UPDATE organization_nodes n INNER JOIN tmp_org_allocations a ON a.id=n.id SET n.balance=n.credit_limit-a.allocated");
        $this->execute('DROP TEMPORARY TABLE tmp_org_allocations');
    }

    public function down(): void {}
}
