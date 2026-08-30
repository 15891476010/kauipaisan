<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\service\BetSettlement;
use app\service\QuickEntryParser;

$parser = new QuickEntryParser();
$settlement = new BetSettlement();
$passed = 0;
$failed = [];

$assert = static function (bool $condition, string $name, mixed $detail = null) use (&$passed, &$failed): void {
    if ($condition) { $passed++; return; }
    $failed[] = $name . ($detail === null ? '' : ' ' . json_encode($detail, JSON_UNESCAPED_UNICODE));
};

$parse = static fn(string $text): array => $parser->parse($text, '福彩3D', 2.0);
$assertMatch = static function (string $text, string $draw, bool $expected) use ($parse, $settlement, $assert): void {
    $rows = $parse($text);
    $ok = $rows !== [] && ($rows[0]['status'] ?? '') === 'success';
    $matched = false;
    foreach ($rows as $row) {
        $matched = $matched || $settlement->numberMatches((string)($row['number_text'] ?? ''), $draw, $text);
    }
    $assert($ok && $matched === $expected, "match {$text}/{$draw}", $rows);
};

foreach ([2, 3, 4, 5, 6, 7, 8, 9] as $count) {
    $digits = substr('123456789', 0, $count);
    $word = [2=>'两',3=>'三',4=>'四',5=>'五',6=>'六',7=>'七',8=>'八',9=>'九'][$count];
    $row = $parse($digits . '组三' . $word . '码各1元')[0] ?? [];
    $assert(($row['status'] ?? '') === 'success', "parse 组三{$count}码", $row);
}
foreach ([4, 5, 6, 7, 8, 9] as $count) {
    $digits = substr('123456789', 0, $count);
    $word = [4=>'四',5=>'五',6=>'六',7=>'七',8=>'八',9=>'九'][$count];
    $row = $parse($digits . '组六' . $word . '码各1元')[0] ?? [];
    $assert(($row['status'] ?? '') === 'success', "parse 组六{$count}码", $row);
}
foreach ([3, 4, 5, 6, 7, 8, 9] as $count) {
    $digits = substr('123456789', 0, $count);
    $word = [3=>'三',4=>'四',5=>'五',6=>'六',7=>'七',8=>'八',9=>'九'][$count];
    $row = $parse($digits . '复式' . $word . '码各1元')[0] ?? [];
    $assert(($row['status'] ?? '') === 'success', "parse 复式{$count}码", $row);
}
foreach ([1, 2, 3, 4, 5, 6, 7] as $count) {
    $digits = substr('123456789', 0, $count);
    $word = [1=>'一',2=>'二',3=>'三',4=>'四',5=>'五',6=>'六',7=>'七'][$count];
    foreach ([['组三赖', [36,68,96,120,140,158,168]], ['组六赖', [72,128,170,200,220,232,238]]] as [$family, $amounts]) {
        $row = $parse($digits . $family . $word . '码1倍')[0] ?? [];
        $assert(($row['status'] ?? '') === 'success' && (float)($row['amount'] ?? 0) == $amounts[$count - 1], "parse {$family}{$count}码", $row);
    }
}

foreach ([
    ['123组三三码各1元', '112', true], ['123组三三码各1元', '123', false],
    ['1234组六四码各1元', '421', true], ['1234组六四码各1元', '112', false],
    ['124复式三码各1元', '112', true], ['124复式三码各1元', '111', true], ['124复式三码各1元', '145', false],
    ['1拖34组三胆拖各1元', '133', true], ['1拖34组三胆拖各1元', '344', false],
    ['57拖0123489组六2胆拖各1元', '570', true], ['57拖0123489组六2胆拖各1元', '557', false],
    ['6拖01234单选全胆拖各1元', '660', true], ['6拖01234单选全胆拖各1元', '006', true], ['6拖01234单选全胆拖各1元', '678', false],
    ['345组三赖三码1倍', '522', true], ['345组三赖三码1倍', '345', false],
    ['345组六赖三码1倍', '123', true], ['345组六赖三码1倍', '344', false],
    ['跨度3各1元', '522', true], ['跨度0各1元', '111', true], ['和值9各1元', '522', true],
    ['9独胆各1元', '529', true], ['09双飞各1元', '908', true], ['09双飞各1元', '118', false],
    ['11对子各1元', '111', true], ['11对子各1元', '123', false],
    ['378直各1元', '378', true], ['378直各1元', '387', false], ['378组各1元', '873', true],
    ['百5各1元', '512', true], ['百5各1元', '152', false], ['百12十34个567各1元', '236', true],
    ['豹子全包各1元', '777', true], ['组三全包各1元', '522', true], ['组三全包各1元', '123', false],
    ['组六全包各1元', '123', true], ['组六全包各1元', '122', false], ['对子全包各1元', '122', true],
    ['和大各1元', '999', true], ['和大各1元', '522', false], ['和小各1元', '522', true],
    ['和单各1元', '522', true], ['和双各1元', '522', false],
    ['37810直', '378', true], ['37810直', '387', false],
    ['37810组', '873', true], ['37810组', '112', false],
] as [$text, $draw, $expected]) $assertMatch($text, $draw, $expected);

$compactBoth = $parse('37810直组');
$assert(count($compactBoth) === 2
    && ($compactBoth[0]['number_text'] ?? '') === '378直'
    && ($compactBoth[1]['number_text'] ?? '') === '378组'
    && $settlement->numberMatches('378直', '378', '37810直组')
    && $settlement->numberMatches('378组', '873', '37810直组'), 'compact 直组拆分', $compactBoth);

$detailPayout = new ReflectionMethod($settlement, 'detailPayout');
$detailPayout->setAccessible(true);
$positionPayout = $detailPayout->invoke($settlement,
    ['百012345678十01234569个08964532定位'],
    '828',
    '福百012345678 十01234569 个08964532各10米',
    5760.00,
    900.00
);
$assert(($positionPayout['matched'] ?? 0) === 1
    && abs((float)($positionPayout['stake'] ?? 0) - 10.00) < 0.001
    && abs((float)($positionPayout['win'] ?? 0) - 9000.00) < 0.001,
    '定位复式单号按组合数分摊赔付', $positionPayout);

foreach ([
    ['沾边赖34组三1倍', '组三赖二码', '68.00'],
    ['沾边赖345组六1倍', '组六赖三码', '170.00'],
    ['粘边赖34组三1倍', '组三赖二码', '68.00'],
    ['福3粘边赖组三1倍', '组三赖一码', '36.00'],
    ['福23粘边赖组三1倍', '组三赖二码', '68.00'],
    ['福123粘边赖组三1倍', '组三赖三码', '96.00'],
    ['福1234粘边赖组三1倍', '组三赖四码', '120.00'],
    ['福12345粘边赖组三1倍', '组三赖五码', '140.00'],
    ['福123456粘边赖组三1倍', '组三赖六码', '158.00'],
    ['组三粘边赖34 1倍', '组三赖二码', '68.00'],
    ['组三1倍粘边赖34', '组三赖二码', '68.00'],
    ['福3占边赖组三1倍', '组三赖一码', '36.00'],
] as [$text, $play, $amount]) {
    $rows = $parse($text);
    $assert(count($rows) === 1 && ($rows[0]['status'] ?? '') === 'success'
        && ($rows[0]['play_type'] ?? '') === $play && ($rows[0]['amount'] ?? '') === $amount,
        "粘边赖别名 {$text}", $rows);
}

foreach (['1234复式组六各1元', '123组三复式各1元', '123复式豹子各1元', '1234复试组六各1元'] as $text) {
    $rows = $parse($text);
    $assert(count($rows) === 2 && array_reduce($rows, static fn(bool $ok, array $row): bool => $ok && ($row['status'] ?? '') === 'success', true), "composite {$text}", $rows);
}
$sizeRows = $parse('大小各1元');
$assert(count($sizeRows) === 2, '大小拆分为两条玩法', $sizeRows);

echo 'BetSettlementRulesTest PASS=' . $passed . ' FAIL=' . count($failed) . PHP_EOL;
foreach ($failed as $failure) echo 'FAIL ' . $failure . PHP_EOL;
exit($failed === [] ? 0 : 1);
