<?php
declare(strict_types=1);

use think\migration\Migrator;

final class ReconcileLegacyUserScores extends Migrator
{
    public function up(): void
    {
        $this->execute("UPDATE organization_nodes n LEFT JOIN (SELECT site_id,SUM(balance+credit_balance) allocated FROM site_users WHERE organization_id IS NULL AND deleted_at IS NULL GROUP BY site_id) u ON u.site_id=n.site_id SET n.balance=n.balance-COALESCE(u.allocated,0) WHERE n.parent_id=0 AND n.deleted_at IS NULL");
        $this->execute("DELETE FROM organization_credit_ledger WHERE source_type='opening_balance'");
        $now=date('Y-m-d H:i:s');
        $this->execute("INSERT INTO organization_credit_ledger(transaction_no,tenant_id,site_id,organization_id,account_type,account_id,direction,amount,balance_before,balance_after,reason,source_type,category,note,created_at) SELECT CONCAT('OPEN-PL-IN-',p.tenant_id),p.tenant_id,0,NULL,'platform',p.id,'in',p.total_score,0,p.total_score,'总平台期初总分','opening_balance','opening','系统上线时建立期初总账','{$now}' FROM platform_credit_accounts p WHERE p.total_score>0");
        $this->execute("INSERT INTO organization_credit_ledger(transaction_no,tenant_id,site_id,organization_id,account_type,account_id,direction,amount,balance_before,balance_after,reason,source_type,category,note,created_at) SELECT CONCAT('OPEN-PL-OUT-',p.tenant_id),p.tenant_id,0,NULL,'platform',p.id,'out',p.total_score-p.balance,p.total_score,p.balance,'期初已向下分配','opening_balance','opening','系统上线前已经分配的分数','{$now}' FROM platform_credit_accounts p WHERE p.total_score-p.balance>0");
        $this->execute("INSERT INTO organization_credit_ledger(transaction_no,tenant_id,site_id,organization_id,account_type,account_id,direction,amount,balance_before,balance_after,reason,source_type,category,note,created_at) SELECT CONCAT('OPEN-ORG-',n.id),n.tenant_id,n.site_id,n.id,'organization',n.id,IF(n.balance>=0,'in','out'),ABS(n.balance),0,n.balance,'组织期初可用分数','opening_balance','opening','系统上线时建立组织期初余额','{$now}' FROM organization_nodes n WHERE n.balance<>0 AND n.deleted_at IS NULL");
        $this->execute("INSERT INTO organization_credit_ledger(transaction_no,tenant_id,site_id,organization_id,account_type,account_id,related_user_id,direction,amount,balance_before,balance_after,reason,source_type,category,note,created_at) SELECT CONCAT('OPEN-USER-IN-',u.id),u.tenant_id,u.site_id,u.organization_id,'user',u.id,u.id,IF(u.balance+u.credit_balance>=0,'in','out'),ABS(u.balance+u.credit_balance),0,u.balance+u.credit_balance,'用户期初总分','opening_balance','opening','系统上线时建立用户期初总分','{$now}' FROM site_users u WHERE u.balance+u.credit_balance<>0 AND u.deleted_at IS NULL");
        $this->execute("INSERT INTO organization_credit_ledger(transaction_no,tenant_id,site_id,organization_id,account_type,account_id,related_user_id,direction,amount,balance_before,balance_after,reason,source_type,category,note,created_at) SELECT CONCAT('OPEN-USER-LOCK-',u.id),u.tenant_id,u.site_id,u.organization_id,'user',u.id,u.id,'out',u.used_balance,u.balance+u.credit_balance,u.balance+u.credit_balance-u.used_balance,'期初下注锁定分数','opening_balance','opening','尚未开奖的下注占用分数','{$now}' FROM site_users u WHERE u.used_balance>0 AND u.deleted_at IS NULL");
    }
    public function down(): void {}
}
