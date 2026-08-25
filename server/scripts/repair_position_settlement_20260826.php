<?php
declare(strict_types=1);

require dirname(__DIR__, 1).'/vendor/autoload.php';

$app = new think\App(dirname(__DIR__, 1));
$app->initialize();

use app\service\CreditLedger;
use think\facade\Db;

$source = '福体百0234569十0234689个1235679各1米';
$submissionId = 13;
$recordIds = [128, 127];
$correctionPerRecord = 307800.00;
$organizationCorrection = [27 => 153900.00, 25 => 61560.00, 24 => 73872.00, 22 => 9234.00, 21 => 9234.00];

Db::transaction(function () use ($source, $submissionId, $recordIds, $correctionPerRecord, $organizationCorrection): void {
    $records = [];
    foreach ($recordIds as $recordId) {
        $record = Db::name('bet_records')->where('id', $recordId)->lock(true)->find();
        if (!is_array($record) || (int)$record['submission_id'] !== $submissionId || (string)$record['source_text'] !== $source) {
            throw new RuntimeException('目标主单校验失败，已停止清洗');
        }
        if ((float)$record['win_amount'] !== $correctionPerRecord + 900.00 || (string)$record['status'] !== 'won') {
            throw new RuntimeException('目标主单不是预期的错误结算状态，已停止清洗');
        }
        $detail = Db::name('bet_details')->where('bet_record_id', $recordId)->lock(true)->find();
        if (!is_array($detail) || (float)$detail['amount'] !== 343.00 || (float)$detail['odds'] !== 900.00 || (float)$detail['win_amount'] !== $correctionPerRecord + 900.00) {
            throw new RuntimeException('目标明细校验失败，已停止清洗');
        }
        $tokens = preg_split('/\s+/', trim((string)$detail['number_text'])) ?: [];
        if (count($tokens) !== 343 || !in_array('522', $tokens, true) && !in_array('431', $tokens, true)) {
            throw new RuntimeException('目标明细组合数量校验失败，已停止清洗');
        }
        $records[$recordId] = ['record' => $record, 'detail' => $detail];
    }

    $userId = (int)$records[$recordIds[0]]['record']['user_id'];
    $siteId = (int)$records[$recordIds[0]]['record']['site_id'];
    $user = Db::name('site_users')->where('id', $userId)->where('site_id', $siteId)->lock(true)->find();
    if (!is_array($user)) throw new RuntimeException('目标用户不存在，已停止清洗');

    foreach ($records as $recordId => $payload) {
        Db::name('bet_details')->where('id', (int)$payload['detail']['id'])->update(['win_amount' => '900.00', 'status' => 'won']);
        Db::name('bet_records')->where('id', $recordId)->update(['win_amount' => '900.00', 'status' => 'won']);
    }

    $balanceBefore = (float)$user['balance'];
    $balanceAfter = round($balanceBefore - $correctionPerRecord * count($records), 2);
    if ($balanceAfter < 0) throw new RuntimeException('用户余额不足以执行结算更正，已停止清洗');
    Db::name('site_users')->where('id', $userId)->where('site_id', $siteId)->update(['balance' => number_format($balanceAfter, 2, '.', ''), 'updated_at' => date('Y-m-d H:i:s')]);
    $organizationId = (int)($user['organization_id'] ?? 0);
    $runningBalance = (float)$user['balance'];
    foreach ($records as $recordId => $payload) {
        $record = $payload['record'];
        $nextBalance = round($runningBalance - $correctionPerRecord, 2);
        CreditLedger::write(
            ['tenant_id' => (int)$record['tenant_id'], 'site_id' => $siteId],
            $organizationId ?: null,
            'user',
            $userId,
            $userId,
            (int)$recordId,
            null,
            (string)$record['issue_no'],
            -$correctionPerRecord,
            $runningBalance,
            $nextBalance,
            '结算更正：定位复式误派奖金',
            'manual_adjustment',
        );
        $runningBalance = $nextBalance;
    }

    foreach ($records as $recordId => $payload) {
        $record = $payload['record'];
        foreach ($organizationCorrection as $organizationId => $delta) {
            $node = Db::name('organization_nodes')->where('id', $organizationId)->lock(true)->find();
            if (!is_array($node)) throw new RuntimeException('组织节点不存在，已停止清洗');
            $before = (float)$node['balance'];
            $after = round($before + $delta, 2);
            Db::name('organization_nodes')->where('id', $organizationId)->update(['balance' => number_format($after, 2, '.', ''), 'updated_at' => date('Y-m-d H:i:s')]);
            CreditLedger::write(
                ['tenant_id' => (int)$record['tenant_id'], 'site_id' => $siteId],
                (int)$organizationId,
                'organization',
                (int)$organizationId,
                $userId,
                (int)$recordId,
                null,
                (string)$record['issue_no'],
                $delta,
                $before,
                $after,
                '结算更正：定位复式误派奖金',
                'manual_adjustment',
            );
        }
    }

    $submission = Db::name('bet_submissions')->where('id', $submissionId)->lock(true)->find();
    if (!is_array($submission)) throw new RuntimeException('提交汇总不存在，已停止清洗');
    Db::name('bet_submissions')->where('id', $submissionId)->update(['win_amount' => '1800.00', 'status' => 'won']);

    $billDate = substr((string)$records[$recordIds[0]]['record']['placed_at'], 0, 10);
    $bill = Db::name('bills')->where('site_id', $siteId)->where('user_id', $userId)->where('bill_date', $billDate)->lock(true)->find();
    if (!is_array($bill) || (float)$bill['win_amount'] < $correctionPerRecord * count($records)) throw new RuntimeException('用户账单校验失败，已停止清洗');
    $newWin = round((float)$bill['win_amount'] - $correctionPerRecord * count($records), 2);
    $newProfit = round((float)$bill['profit'] - $correctionPerRecord * count($records), 2);
    Db::name('bills')->where('id', (int)$bill['id'])->update(['win_amount' => number_format($newWin, 2, '.', ''), 'profit' => number_format($newProfit, 2, '.', '')]);
});

echo "Position settlement correction completed: submission {$submissionId}, records ".implode(',', $recordIds)."\n";
