<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class CreditLedger
{
    public static function userBet(array $session, int $userId, float $amount, float $before, int $recordId, string $issue): void
    {
        self::write($session, null, 'user', $userId, $userId, $recordId, null, $issue, -$amount, $before, $before - $amount, '下注扣除分数', 'bet');
    }

    public static function userSettlement(array $record, float $payout, float $before, float $after): void
    {
        if ($payout <= 0) return;
        $session=['tenant_id'=>(int)$record['tenant_id'], 'site_id'=>(int)$record['site_id']];
        self::write($session, (int)($record['organization_id'] ?? 0) ?: null, 'user', (int)$record['user_id'], (int)$record['user_id'], (int)$record['id'], null, (string)$record['issue_no'], $payout, $before, $after, '中奖增加分数', 'settlement');
    }

    public static function organizationSettlement(array $record, int $organizationId, float $change, float $before, float $after, string $reason, array $metadata=[]): void
    {
        $session=['tenant_id'=>(int)$record['tenant_id'], 'site_id'=>(int)$record['site_id']];
        self::writeExtended($session,ScoreTransfer::transactionNo('LG'),$organizationId,'organization',$organizationId,(int)$record['user_id'],(int)$record['id'],null,(string)$record['issue_no'],$change,$before,$after,$reason,'settlement_share','settlement',[],null,null,null,$metadata);
    }

    public static function platformSettlement(array $record, int $accountId, float $change, float $before, float $after): void
    {
        $session=['tenant_id'=>(int)$record['tenant_id'], 'site_id'=>(int)$record['site_id']];
        self::write($session, null, 'platform', $accountId, (int)$record['user_id'], (int)$record['id'], null, (string)$record['issue_no'], $change, $before, $after, $change >= 0 ? '平台本期投注盈利' : '平台本期投注亏损承担', 'settlement_share');
    }

    public static function write(array $session, ?int $organizationId, string $accountType, int $accountId, ?int $userId, ?int $recordId, ?int $detailId, ?string $issue, float $delta, float $before, float $after, string $reason, string $source): void
    {
        self::writeExtended($session,ScoreTransfer::transactionNo('LG'),$organizationId,$accountType,$accountId,$userId,$recordId,$detailId,$issue,$delta,$before,$after,$reason,$source,self::category($source));
    }

    public static function writeExtended(array $session,string $transactionNo,?int $organizationId,string $accountType,int $accountId,?int $userId,?int $recordId,?int $detailId,?string $issue,float $delta,float $before,float $after,string $reason,string $source,string $category='other',array $operator=[],?string $counterpartyType=null,?int $counterpartyId=null,?string $note=null,array $metadata=[]): void
    {
        Db::name('organization_credit_ledger')->insert([
            'transaction_no'=>$transactionNo,
            'tenant_id'=>(int)($session['tenant_id'] ?? 0), 'site_id'=>(int)($session['site_id'] ?? 0),
            'organization_id'=>$organizationId, 'account_type'=>$accountType, 'account_id'=>$accountId,
            'related_user_id'=>$userId, 'related_bet_record_id'=>$recordId, 'related_bet_detail_id'=>$detailId,
            'issue_no'=>$issue, 'direction'=>$delta >= 0 ? 'in' : 'out', 'amount'=>number_format(abs($delta),2,'.',''),
            'balance_before'=>number_format($before,2,'.',''), 'balance_after'=>number_format($after,2,'.',''),
            'reason'=>$reason, 'source_type'=>$source, 'category'=>$category,
            'operator_type'=>$operator['type']??null,'operator_id'=>$operator['id']??null,'operator_name'=>$operator['name']??null,
            'counterparty_account_type'=>$counterpartyType,'counterparty_account_id'=>$counterpartyId,'note'=>$note,
            'metadata'=>$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null,'created_at'=>date('Y-m-d H:i:s'),
        ]);
    }

    private static function category(string $source): string
    {
        return match($source){'bet'=>'bet','settlement','settlement_share'=>'settlement','manual_adjustment','platform_total_adjustment'=>'adjustment','score_allocation'=>'allocation',default=>'other'};
    }
}
