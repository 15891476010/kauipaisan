<?php
declare(strict_types=1);

use app\service\CreditLedger;
use app\service\OrganizationHierarchy;
use think\facade\Db;
use think\migration\Migrator;

final class ReconcileUnassignedBetSettlement extends Migrator
{
    private const RECORD_ID = 6;
    private const TRANSACTION_NO = 'RC20260822BET6';

    public function up(): void
    {
        Db::transaction(function(): void {
            if(Db::name('organization_credit_ledger')->where('transaction_no',self::TRANSACTION_NO)->count()>0)return;
            $record=Db::name('bet_records')->where('id',self::RECORD_ID)->lock(true)->find();
            if(!$record||!in_array((string)$record['status'],['won','unwon'],true))throw new RuntimeException('待补账注单6不存在或尚未结算');
            if(Db::name('organization_credit_ledger')->where('related_bet_record_id',self::RECORD_ID)->where('source_type','settlement_share')->count()>0)return;
            $user=Db::name('site_users')->where('id',(int)$record['user_id'])->where('site_id',(int)$record['site_id'])->lock(true)->find();
            if(!$user)throw new RuntimeException('待补账用户不存在');
            $root=OrganizationHierarchy::rootForSite((int)$record['site_id']);
            if(!$root)throw new RuntimeException('待补账站点未配置根总监');
            $root=Db::name('organization_nodes')->where('id',(int)$root['id'])->lock(true)->find();
            $settings=Db::name('sites')->where('id',(int)$record['site_id'])->value('settings');
            $settings=is_string($settings)?json_decode($settings,true):(is_array($settings)?$settings:[]);
            $siteCap=max(0,min(100,(float)($settings['max_profit_share_rate']??100)));
            $share=Db::name('organization_profit_shares')->where('child_organization_id',(int)$root['id'])->where('parent_organization_id',0)->where('status',1)->find();
            $rootRate=$share?max(0,min($siteCap,(float)$share['share_rate'])):$siteCap;
            $rebate=(float)Db::name('bet_details')->where('bet_record_id',self::RECORD_ID)->sum('rebate');
            $houseProfit=round((float)$record['amount']-(float)$record['win_amount']-$rebate,2);
            if(abs($houseProfit-100.0)>0.001)throw new RuntimeException('注单6待补差额已变化，停止自动补账');
            $rootAmount=round($houseProfit*$rootRate/100,2);
            $platformAmount=round($houseProfit-$rootAmount,2);
            $session=['tenant_id'=>(int)$record['tenant_id'],'site_id'=>(int)$record['site_id']];
            $metadata=['reconciliation'=>'unassigned_user_settlement','original_organization_id'=>$user['organization_id']??null,'root_rate'=>$rootRate,'house_profit'=>$houseProfit];

            if(abs($rootAmount)>=0.005){
                $before=(float)$root['balance'];
                Db::name('organization_nodes')->where('id',(int)$root['id'])->update(['balance'=>Db::raw('balance + '.number_format($rootAmount,2,'.','')),'updated_at'=>date('Y-m-d H:i:s')]);
                CreditLedger::writeExtended($session,self::TRANSACTION_NO,(int)$root['id'],'organization',(int)$root['id'],(int)$record['user_id'],(int)$record['id'],null,(string)$record['issue_no'],$rootAmount,$before,$before+$rootAmount,'未归属用户历史结算差额补账','settlement_reconciliation','settlement',['type'=>'system','id'=>0,'name'=>'结算守恒修复'],null,null,'注单6未中奖分成补账',$metadata);
            }
            if(abs($platformAmount)>=0.005){
                $account=Db::name('platform_credit_accounts')->where('tenant_id',(int)$record['tenant_id'])->lock(true)->find();
                if(!$account)throw new RuntimeException('平台分数账户不存在');
                $before=(float)$account['balance'];
                Db::name('platform_credit_accounts')->where('id',(int)$account['id'])->update(['balance'=>Db::raw('balance + '.number_format($platformAmount,2,'.','')),'updated_at'=>date('Y-m-d H:i:s')]);
                CreditLedger::writeExtended($session,self::TRANSACTION_NO,null,'platform',(int)$account['id'],(int)$record['user_id'],(int)$record['id'],null,(string)$record['issue_no'],$platformAmount,$before,$before+$platformAmount,'未归属用户历史结算差额补账','settlement_reconciliation','settlement',['type'=>'system','id'=>0,'name'=>'结算守恒修复'],null,null,'注单6未中奖平台分成补账',$metadata);
            }
        });
    }

    public function down(): void
    {
        // Financial reconciliation is intentionally irreversible.
    }
}
