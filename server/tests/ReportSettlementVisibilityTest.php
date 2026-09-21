<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use app\service\ReportSettlementVisibility;

function checkVisibility(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$metrics = [
    'bet_count' => 2,
    'amount' => 1000.0,
    'viewer_amount' => 600.0,
    'share_amount' => 400.0,
    'win_amount' => 720.0,
    'member_profit' => -280.0,
    'share_profit' => 180.0,
    'agent_profit' => 200.0,
    'platform_profit' => -100.0,
    'water' => 12.0,
    'levels' => [
        'agent' => ['profit' => 200.0, 'share_profit' => 180.0, 'water' => 20.0],
    ],
];

$pending = ReportSettlementVisibility::apply($metrics, false);
checkVisibility($pending['win_amount'] === 0.0, '未结算中奖必须为 0');
checkVisibility($pending['member_profit'] === 0.0, '未结算会员盈亏必须为 0');
checkVisibility($pending['share_profit'] === 0.0 && $pending['agent_profit'] === 0.0, '未结算占成/本级盈亏必须为 0');
checkVisibility($pending['platform_profit'] === 0.0, '未结算平台盈亏必须为 0');
checkVisibility($pending['water'] === 12.0, '未结算回水字段不应被误清除');
checkVisibility($pending['levels']['agent']['profit'] === 0.0 && $pending['levels']['agent']['share_profit'] === 0.0, '未结算层级盈亏必须为 0');

$settled = ReportSettlementVisibility::apply($metrics, true);
checkVisibility($settled === $metrics, '已结算数据必须保持原值');

// 使用真实报表聚合方法，覆盖同一查询同时包含已结算和未结算注单。
$aggregate = new ReflectionMethod(\app\controller\AgentReport::class, 'aggregate');
$mixed = $aggregate->invoke(new \app\controller\AgentReport(), [
    ['metrics' => $settled], ['metrics' => $pending],
]);
checkVisibility($mixed['bet_count'] === 4 && $mixed['amount'] === '2000', '混合查询仍应统计全部笔数和总投');
checkVisibility($mixed['viewer_amount'] === '1200' && $mixed['share_amount'] === '800', '未结算货量必须保留');
checkVisibility($mixed['win_amount'] === '720' && $mixed['member_profit'] === '-280', '混合查询仅汇总已结算中奖和盈亏');
checkVisibility($mixed['levels']['agent']['profit'] === '200', '下级混合盈亏不能提前计入未结算结果');
echo "Report settlement visibility tests passed\n";
