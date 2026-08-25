<?php
declare(strict_types=1);

require dirname(__DIR__, 1).'/vendor/autoload.php';
$app = new think\App(dirname(__DIR__, 1));
$app->initialize();

use app\service\BetSettlement;
use think\facade\Db;

$matcher = new BetSettlement();
$method = new ReflectionMethod($matcher, 'detailPayout');
$method->setAccessible(true);
$rows = Db::name('bet_details')->alias('d')
    ->join('bet_records r', 'r.id=d.bet_record_id')
    ->join('user_stop_drops s', 's.bet_detail_id=d.id')
    ->where('r.status', 'won')
    ->whereLike('d.source_text', '%百%')
    ->whereLike('d.source_text', '%十%')
    ->whereLike('d.source_text', '%个%')
    ->field('d.id,d.bet_record_id,d.number_text,d.amount,d.odds,d.win_amount,d.source_text,d.issue_no,s.lottery,r.status AS record_status')
    ->order('d.id asc')->select()->toArray();
$mismatches = [];
foreach ($rows as $row) {
    $lotteryId = (int)Db::name('lotteries')->where('name', (string)$row['lottery'])->value('id');
    $history = Db::name('lottery_histories')->where('lottery_id', $lotteryId)->where('code', (string)$row['issue_no'])->find();
    if (!is_array($history)) continue;
    $draw = preg_replace('/\D/', '', (string)($history['numbers'] ?? '')) ?: '';
    if (strlen($draw) !== 3) continue;
    $numbers = preg_split('/\s+/', trim((string)$row['number_text'])) ?: [];
    $numbers = array_values(array_filter($numbers, static fn(string $number): bool => trim($number) !== ''));
    $payout = $method->invoke($matcher, $numbers, $draw, (string)$row['source_text'], (float)$row['amount'], (float)$row['odds']);
    if (abs((float)$row['win_amount'] - (float)$payout['win']) > 0.005) {
        $mismatches[] = ['detail_id'=>(int)$row['id'], 'record_id'=>(int)$row['bet_record_id'], 'lottery'=>$row['lottery'], 'issue'=>$row['issue_no'], 'stored'=>(float)$row['win_amount'], 'expected'=>(float)$payout['win'], 'tokens'=>count($numbers), 'matched'=>(int)$payout['matched'], 'source'=>$row['source_text']];
    }
}
echo json_encode(['checked'=>count($rows),'mismatches'=>$mismatches], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
