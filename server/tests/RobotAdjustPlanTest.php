<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));
$app->initialize();

use app\controller\AdminBetBatch;
use think\facade\Db;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function near(float $actual, float $expected, string $message, float $tolerance = 1.0): void
{
    if (abs($actual - $expected) > $tolerance) throw new RuntimeException($message.": expected {$expected}, got {$actual}");
}

$lottery = Db::name('lotteries')->where('status',1)->whereNull('deleted_at')->order('id')->find();
$site = Db::name('sites')->whereNull('deleted_at')->field('id,tenant_id')->order('id')->find();
if (!$lottery || !$site) throw new RuntimeException('缺少彩种或站点基础数据');
$lotteryId = (int)$lottery['id'];
$lotteryName = (string)$lottery['name'];
$siteId = (int)$site['id'];
$tenantId = (int)$site['tenant_id'];
$prefix = 'rbadj_'.bin2hex(random_bytes(4));
$now = date('Y-m-d H:i:s');
$issue = 'ROBOT-'.bin2hex(random_bytes(4));
$draw = '123';

$nodeId = 0; $userA = 0; $userB = 0;
$makeUser = static function(string $name) use ($tenantId,$siteId,&$nodeId,$now): int {
    return (int)Db::name('site_users')->insertGetId([
        'tenant_id'=>$tenantId,'site_id'=>$siteId,'organization_id'=>$nodeId,
        'username'=>$name,'display_name'=>$name,'password'=>password_hash($name,PASSWORD_DEFAULT),
        'balance'=>0,'credit_balance'=>0,'used_balance'=>100,'used_balance_date'=>date('Y-m-d'),'status'=>1,
        'created_at'=>$now,'updated_at'=>$now,
    ]);
};
$makeRecord = static function(int $userId, string $source, string $number, string $amount, float $odds) use ($tenantId,$siteId,$lotteryName,$issue,$now): array {
    $recordId = (int)Db::name('bet_records')->insertGetId([
        'tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$userId,
        'issue_no'=>$issue,'source_text'=>$source,'formatted_text'=>$source,'bet_count'=>1,
        'amount'=>$amount,'win_amount'=>'0.00','status'=>'pending','sealed'=>0,'placed_at'=>$now,'created_at'=>$now,
    ]);
    $detailId = (int)Db::name('bet_details')->insertGetId([
        'tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$userId,
        'bet_record_id'=>$recordId,'issue_no'=>$issue,'number_text'=>$number,'category'=>'直选','amount'=>$amount,
        'odds'=>number_format($odds,3,'.',''),'win_amount'=>'0.00','rebate'=>'0.00','status'=>'pending','placed_at'=>$now,'source_text'=>$source,
    ]);
    Db::name('user_stop_drops')->insert([
        'tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$userId,
        'bet_detail_id'=>$detailId,'lottery'=>$lotteryName,'issue_no'=>$issue,'number_text'=>$number,'play_type'=>'直选',
        'stop_type'=>'none','original_amount'=>$amount,'actual_amount'=>$amount,'stop_amount'=>'0.00',
        'original_odds'=>number_format($odds,3,'.',''),'actual_odds'=>number_format($odds,3,'.',''),'drop_odds'=>'0.000',
        'source_text'=>$source,'placed_at'=>$now,'created_at'=>$now,
    ]);
    return [$recordId,$detailId];
};

Db::startTrans();
try {
    $nodeId = (int)Db::name('organization_nodes')->insertGetId([
        'tenant_id'=>$tenantId,'site_id'=>$siteId,'parent_id'=>0,'level'=>'agent',
        'name'=>$prefix,'code'=>$prefix,'path'=>'/','depth'=>0,
        'credit_limit'=>0,'balance'=>0,'permissions'=>'["*"]','settings'=>'{}',
        'status'=>1,'created_at'=>$now,'updated_at'=>$now,
    ]);
    \app\service\OrganizationHierarchy::rebuildPath($nodeId);
    $userA = $makeUser($prefix.'_a'); // 已选会员
    $userB = $makeUser($prefix.'_b'); // 未选会员
    // A1: 10 元直选 456 —— 可翻号为 123
    [$recFlip,$detFlip] = $makeRecord($userA, '福456直10元', '456', '10.00', 900.0);
    // A2: 5 元直选 123 —— 当前已中 4500
    [$recWin,$detWin] = $makeRecord($userA, '福123直5元', '123', '5.00', 900.0);
    // B: 1000 元直选 789 —— 未选会员净亏 1000
    $makeRecord($userB, '福789直1000元', '789', '1000.00', 900.0);

    $controller = new AdminBetBatch();
    $planMethod = new ReflectionMethod($controller, 'buildRobotPlan');
    $planMethod->setAccessible(true);
    $lotteryRow = ['id'=>$lotteryId,'name'=>$lotteryName];

    // 场景 1：目标 9000 > 当前中奖 4500 —— 已有中奖时优先调金额（不动 A1 的号码）
    $plan = $planMethod->invoke($controller, $lotteryRow, $issue, $draw, [$userA], 9000.0, 0, null);
    check(is_array($plan['items']) && count($plan['items']) === 1, '应只出一条方案');
    $item = $plan['items'][0];
    check($item['action'] === 'scale', '已有中奖应优先调金额而非翻号');
    check((int)$item['record_id'] === $recWin, '应调整已中奖的 A2');
    check((string)$item['new_source'] === '福123直10元', '调额方案原文应同步新金额（5元→10元）');
    check((float)$item['details'][0]['new_amount'] > 5.0, '金额应被放大');
    near((float)$plan['achieved_win'], 9000.0, '方案达成中奖');
    near((float)$plan['denominator'], 1000.0, '未选会员净亏');
    near((float)$plan['ratio'], 900.0, '比率应为 900%', 1.0);
    // A2 金额 5→10、中奖 4500→9000：节点盈亏 = 之前 + 5(注额) - 4500(中奖)
    near((float)$plan['projected']['anchor_profit_after'], (float)$plan['projected']['anchor_profit_before'] + 5.0 - 4500.0, '节点盈亏投影', 1.5);

    // 场景 1b：目标超出缩放承载（4500×50）—— 走翻号路径补充基础中奖
    $planBig = $planMethod->invoke($controller, $lotteryRow, $issue, $draw, [$userA], 250000.0, 0, null);
    check(count($planBig['items']) === 1 && $planBig['items'][0]['action'] === 'flip', '超容量目标应走翻号');
    check((int)$planBig['items'][0]['record_id'] === $recFlip, '应翻 A1 主单');
    check(str_contains((string)$planBig['items'][0]['new_source'], $draw), '新原始注单应包含预开奖号码');
    near((float)$planBig['achieved_win'], 250000.0, '大目标达成中奖', 50.0);

    // 场景 2：目标 500 < 当前中奖 4500 —— 纯收缩中奖注单金额
    $plan2 = $planMethod->invoke($controller, $lotteryRow, $issue, $draw, [$userA], 500.0, 0, null);
    check(count($plan2['items']) === 1, '收缩方案应只有一条');
    check($plan2['items'][0]['action'] === 'scale', '应为调额方案');
    check((int)$plan2['items'][0]['record_id'] === $recWin, '应调整已中奖的 A2');
    near((float)$plan2['achieved_win'], 500.0, '收缩达成中奖（分位网格取最近）', 5.0);
    check((float)$plan2['items'][0]['new_amount'] < 5.0, '金额应被收缩');

    // 场景 3：不可翻玩法 —— 组三 token 在全异开奖号下无法中奖
    [$recZ3,$detZ3] = $makeRecord($userA, '福112组三10元', '112组三', '10.00', 300.0);
    Db::name('bet_details')->where('id',$detZ3)->update(['category'=>'组三']);
    Db::name('user_stop_drops')->where('bet_detail_id',$detZ3)->update(['play_type'=>'组三']);
    $plan3 = $planMethod->invoke($controller, $lotteryRow, $issue, $draw, [$userA], 6000.0, 0, null);
    $flipIds = array_map(static fn(array $i): int => (int)$i['record_id'], $plan3['items']);
    check(!in_array($recZ3, $flipIds, true), '组三注单不应被翻转');
    $warn = implode('；', $plan3['warnings']);
    check(str_contains($warn, '无法中奖') || str_contains($warn, '不可改号'), '应给出不可翻告警');
    near((float)$plan3['achieved_win'], 6000.0, '含不可翻单时达成中奖（分位网格取最近）', 5.0);

    // 场景 4b：扩展玩法翻号 —— 独胆/组选多码/胆拖/定位/和值/跨度 都可改为中奖，豹子全包不可翻
    $userC = $makeUser($prefix.'_c');
    $mk = static function(string $source,string $number,string $amount,float $odds,string $cat) use ($makeRecord,$userC): array {
        [$rid,$did] = $makeRecord($userC,$source,$number,$amount,$odds);
        Db::name('bet_details')->where('id',$did)->update(['category'=>$cat]);
        return [$rid,$did];
    };
    [$rDudan,$dDudan] = $mk('福5独胆10元','5','10.00',20.0,'独胆');
    [$rZ6,$dZ6] = $mk('福12467组六五码10元','六12467','10.00',80.0,'组六多码');
    [$rDt,$dDt] = $mk('组六胆拖 胆5拖6789 1码拖4 福','胆5拖6789','10.00',80.0,'组六胆拖');
    [$rDw,$dDw] = $mk('福百5一码定位10元','百位5','10.00',10.0,'一码定位');
    [$rHz,$dHz] = $mk('和值15 福','和值15','10.00',9.0,'和值');
    [$rKd,$dKd] = $mk('跨度7 福','跨度7','10.00',9.0,'跨度');
    [$rBz,$dBz] = $mk('豹子全包 福','豹子全包','10.00',900.0,'豹子全包'); // 开奖 123 非豹子 → 恒不中
    $plan5 = $planMethod->invoke($controller, $lotteryRow, $issue, $draw, [$userC], 2000.0, 0, null);
    $flipIds5 = array_map(static fn(array $i): int => (int)$i['record_id'], $plan5['items']);
    foreach ([$rDudan,$rZ6,$rDt,$rDw,$rHz,$rKd] as $rid) check(in_array($rid,$flipIds5,true),'扩展玩法注单 #'.$rid.' 应被翻转');
    check(!in_array($rBz,$flipIds5,true),'豹子全包在非豹子开奖下不可翻');
    near((float)$plan5['achieved_win'], 2000.0, '扩展玩法方案达成中奖（分位网格取最近）', 40.0);

    // 应用独胆方案：号码、明细源文本、原始注单文本都应同步
    $dudanItem = null;
    foreach ($plan5['items'] as $it) if ((int)$it['record_id']===$rDudan) $dudanItem=$it;
    check($dudanItem !== null, '独胆方案项应存在');
    $applyMethod0 = new ReflectionMethod($controller, 'applyRobotItem');
    $applyMethod0->setAccessible(true);
    $settledIds0 = [];
    $applyMethod0->invokeArgs($controller, [$dudanItem, $issue, null, &$settledIds0]);
    $d = Db::name('bet_details')->where('id',$dDudan)->find();
    check((string)$d['number_text'] === '1', '独胆号码应改为开奖数字');
    check(str_contains((string)$d['source_text'], '1独胆'), '独胆明细源文本应同步改号');
    $rec = Db::name('bet_records')->where('id',$rDudan)->find();
    check(str_contains((string)$rec['source_text'], '1独胆'), '原始注单文本应同步改为开奖独胆');

    // 应用组六胆拖方案：胆/拖和明细源文本都应改写
    $dtItem = null;
    foreach ($plan5['items'] as $it) if ((int)$it['record_id']===$rDt) $dtItem=$it;
    check($dtItem !== null, '胆拖方案项应存在');
    $applyMethod0->invokeArgs($controller, [$dtItem, $issue, null, &$settledIds0]);
    $d = Db::name('bet_details')->where('id',$dDt)->find();
    check(preg_match('/^胆1拖/', (string)$d['number_text']) === 1, '胆拖胆码应改为开奖数字');
    check(str_contains((string)$d['source_text'], (string)$d['number_text']), '胆拖明细源文本应同步');
    $rec = Db::name('bet_records')->where('id',$rDt)->find();
    check(str_contains((string)$rec['source_text'], (string)$d['number_text']), '原始注单文本应同步胆拖改号');

    // 场景 4c：多码直选只把一个号码改成开奖号，其余保留
    $userD = $makeUser($prefix.'_d');
    [$rM,$dM] = $makeRecord($userD, '福001 002 003直各10元', '001直 002直 003直', '30.00', 900.0);
    $plan6 = $planMethod->invoke($controller, $lotteryRow, $issue, $draw, [$userD], 9000.0, 0, null);
    check(count($plan6['items']) === 1 && $plan6['items'][0]['action'] === 'flip', '多码直选应出一条翻号方案');
    check($plan6['items'][0]['details'][0]['new_number'] === '123直 002直 003直', '多码直选只应把一个号码改成开奖号');
    check(substr_count((string)$plan6['items'][0]['new_source'], $draw) === 1, '原始注单只应出现一个开奖号');

    // 场景 4d：已中奖的多码注单不重复翻号 —— 调额达成目标，原文/号码不变
    $userE = $makeUser($prefix.'_e');
    [$rE,$dE] = $makeRecord($userE, '福123 456直各10元', '123直 456直', '20.00', 900.0);
    $plan7 = $planMethod->invoke($controller, $lotteryRow, $issue, $draw, [$userE], 18000.0, 0, null);
    check(count($plan7['items']) === 1 && $plan7['items'][0]['action'] === 'scale', '已中奖多码注单应调额而非翻号');
    check((string)$plan7['items'][0]['new_source'] === '福123 456直各20元', '调额方案原文应同步每注新分摊（各10元→各20元）');
    check($plan7['items'][0]['details'][0]['new_number'] === '123直 456直', '已中奖明细号码不应改动');
    near((float)$plan7['achieved_win'], 18000.0, '调额达成中奖', 5.0);

    // 场景 4：应用落库 —— 调额方案落库（金额/用量同步，号码与原文不变）
    $usedBefore = (float)Db::name('site_users')->where('id',$userA)->value('used_balance');
    $applyMethod = new ReflectionMethod($controller, 'applyRobotItem');
    $applyMethod->setAccessible(true);
    $settledIds = [];
    $applyMethod->invokeArgs($controller, [$plan['items'][0], $issue, null, &$settledIds]);
    $detail = Db::name('bet_details')->where('id',$detWin)->find();
    check((string)$detail['number_text'] === '123', '调额方案明细号码不应改动');
    check(abs((float)$detail['amount'] - (float)$plan['items'][0]['details'][0]['new_amount']) < 0.005, '明细金额应按方案缩放');
    $record = Db::name('bet_records')->where('id',$recWin)->find();
    check((string)$record['source_text'] === '福123直10元', '调额落库后原始注单金额字样应同步');
    near((float)$record['amount'], (float)$plan['items'][0]['new_amount'], '主单金额', 0.01);
    $stop = Db::name('user_stop_drops')->where('bet_detail_id',$detWin)->find();
    check((string)$stop['number_text'] === '123', '停押表明细号码不应改动');
    $usedAfter = (float)Db::name('site_users')->where('id',$userA)->value('used_balance');
    near($usedAfter - $usedBefore, (float)$plan['items'][0]['new_amount'] - 5.0, '当日用量应按金额差调整', 0.02);

    // 翻号方案落库：A1 的 456 应改为开奖号
    $applyMethod->invokeArgs($controller, [$planBig['items'][0], $issue, null, &$settledIds]);
    $detail = Db::name('bet_details')->where('id',$detFlip)->find();
    check((string)$detail['number_text'] === $draw, '翻号方案明细号码应改为预开奖号码');
    $record = Db::name('bet_records')->where('id',$recFlip)->find();
    check(str_contains((string)$record['source_text'], $draw), '翻号方案原始注单文本应同步改号');
    $stop = Db::name('user_stop_drops')->where('bet_detail_id',$detFlip)->find();
    check((string)$stop['number_text'] === $draw, '翻号方案停押表号码应同步');

    Db::rollback();
    echo "RobotAdjustPlanTest passed; fixtures rolled back\n";
} catch (Throwable $error) {
    Db::rollback();
    throw $error;
}
