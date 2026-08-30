<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\service\QuickEntryParser;

$parser = new QuickEntryParser();
$stage = $parser->scanStages('福13457组六 十倍', '福彩3D', 2.0);
if ($stage->category !== '福' || $stage->lotteryCode !== app\service\LotteryCode::FU || $stage->numbers !== ['13457'] || $stage->plays !== ['组六'] || $stage->playCodes !== [app\service\PlayCode::GROUP_SIX] || $stage->amount !== 20.0 || $stage->failed()) {
    fwrite(STDERR, "Failed: staged scanner\n" . json_encode(['category'=>$stage->category,'numbers'=>$stage->numbers,'plays'=>$stage->plays,'amount'=>$stage->amount,'error'=>$stage->error], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$cases = [
    ['123 456 789 直选各1元', '福彩3D', 3, '3.00', '福'],
    ['壹贰叁 贰叁肆 组六各2角', '福彩3D', 2, '0.40', '福'],
    ['福体百0234569十0234689个1235679各10合计6860', '福彩3D', 686, '6860.00', '福体'],
    ['福体百2345678十2345689个0134579各7合计4802', '福彩3D', 686, '4802.00', '福体'],
];

foreach ($cases as [$text, $lottery, $expectedCount, $expectedAmount, $expectedCategory]) {
    $line = $parser->parse($text, $lottery)[0] ?? null;
    if (
        !is_array($line)
        || $line['status'] !== 'success'
        || $line['count'] !== $expectedCount
        || $line['amount'] !== $expectedAmount
        || $line['category'] !== $expectedCategory
    ) {
        fwrite(STDERR, "Failed: {$text}\n" . json_encode($line, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
        exit(1);
    }
}

$failed = $parser->parse("123 456 789 直选各1元\n测试", '福彩3D');
if (($failed[1]['reason'] ?? null) !== '未识别到有效号码') {
    fwrite(STDERR, "Failed: invalid line reason\n");
    exit(1);
}

$multilinePosition = $parser->parse("福百 0126789\n十 0134567\n个 124567 各 1 元 343", '福彩3D');
if (count($multilinePosition) !== 1 || ($multilinePosition[0]['status'] ?? null) !== 'success' || ($multilinePosition[0]['count'] ?? 0) !== 294 || ($multilinePosition[0]['amount'] ?? null) !== '294.00') {
    fwrite(STDERR, "Failed: multiline position\n" . json_encode($multilinePosition, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$basicThreePositionOdds = $parser->parse("福百123456\n十012345\n个123456\n各100米", '福彩3D', 2.0);
if (count($basicThreePositionOdds) !== 1
    || ($basicThreePositionOdds[0]['status'] ?? '') !== 'success'
    || ($basicThreePositionOdds[0]['play_type'] ?? '') !== '三码定位'
    || ($basicThreePositionOdds[0]['number_text'] ?? '') !== '百123456 十012345 个123456'
    || ($basicThreePositionOdds[0]['amount'] ?? '') !== '21600.00') {
    fwrite(STDERR, "Failed: basic three-position odds identity\n" . json_encode($basicThreePositionOdds, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$threePositionSets = $parser->parse('福体百1234567十7654321个567890各1米', '福彩3D');
if (($threePositionSets[0]['status'] ?? null) !== 'success'
    || ($threePositionSets[0]['count'] ?? 0) !== 588
    || ($threePositionSets[0]['amount'] ?? null) !== '588.00') {
    fwrite(STDERR, "Failed: three-position digit sets\n" . json_encode($threePositionSets, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$threePositionMissingAmount = $parser->parse('福体百1234567十7654321个567890', '福彩3D');
if (($threePositionMissingAmount[0]['status'] ?? null) !== 'failed'
    || ($threePositionMissingAmount[0]['reason'] ?? null) !== '未识别到有效金额'
    || str_contains((string)($threePositionMissingAmount[0]['number_text'] ?? ''), '55653220')) {
    fwrite(STDERR, "Failed: position marker mistaken for amount\n" . json_encode($threePositionMissingAmount, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$multiDan = $parser->parse('体16胆各500', '排列三', 2.0);
if (($multiDan[0]['status'] ?? null) !== 'success'
    || ($multiDan[0]['number_text'] ?? null) !== '1 6'
    || ($multiDan[0]['count'] ?? 0) !== 2
    || ($multiDan[0]['amount'] ?? null) !== '1000.00') {
    fwrite(STDERR, "Failed: multiple standalone dan digits\n" . json_encode($multiDan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$endpointDan = $parser->parse('独胆1-3各500', '排列三', 2.0);
if (($endpointDan[0]['status'] ?? null) !== 'success'
    || ($endpointDan[0]['number_text'] ?? null) !== '1 3'
    || ($endpointDan[0]['amount'] ?? null) !== '1000.00') {
    fwrite(STDERR, "Failed: endpoint standalone dan\n" . json_encode($endpointDan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$endpointDanTotal = $parser->parse("福独胆2-8各50\n🈴100", '福彩3D', 2.0);
if (count($endpointDanTotal) !== 1
    || ($endpointDanTotal[0]['status'] ?? null) !== 'success'
    || ($endpointDanTotal[0]['number_text'] ?? null) !== '2 8'
    || ($endpointDanTotal[0]['amount'] ?? null) !== '100.00') {
    fwrite(STDERR, "Failed: endpoint dan with total line\n" . json_encode($endpointDanTotal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$groupCombinationTotal = $parser->parse('福 880 942 869 908 248一组合10米', '福彩3D', 2.0);
if (count($groupCombinationTotal) !== 2
    || array_filter($groupCombinationTotal, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($groupCombinationTotal, 'play_type') !== ['组三', '组六']
    || array_column($groupCombinationTotal, 'amount') !== ['2.00', '8.00']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $groupCombinationTotal)) - 10.0) > 0.001) {
    fwrite(STDERR, "Failed: group combination whole-ticket total\n" . json_encode($groupCombinationTotal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$fuTiSixCodeSharedPlay = $parser->parse("福六码034689\n体六码245678\n组六30组三20\n🈴100", '福彩3D', 2.0);
if (count($fuTiSixCodeSharedPlay) !== 4
    || array_filter($fuTiSixCodeSharedPlay, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($fuTiSixCodeSharedPlay, 'category') !== ['福', '福', '体', '体']
    || array_column($fuTiSixCodeSharedPlay, 'play_type') !== ['组六六码', '组三六码', '组六六码', '组三六码']
    || array_column($fuTiSixCodeSharedPlay, 'amount') !== ['30.00', '20.00', '30.00', '20.00']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $fuTiSixCodeSharedPlay)) - 100.0) > 0.001) {
    fwrite(STDERR, "Failed: Fu/Ti six-code shared plays\n" . json_encode($fuTiSixCodeSharedPlay, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$inlineAndGrandTotal = $parser->parse("福548、543、180一直组🈴️6\n03589、13456组六10组三5\n\n🈴️36", '福彩3D', 2.0);
if (count($inlineAndGrandTotal) !== 5
    || array_filter($inlineAndGrandTotal, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($inlineAndGrandTotal, 'amount') !== ['6.00', '10.00', '5.00', '10.00', '5.00']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $inlineAndGrandTotal)) - 36.0) > 0.001) {
    fwrite(STDERR, "Failed: inline ticket total plus grand total\n" . json_encode($inlineAndGrandTotal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$valueGroup = $parser->parse('福276一倍值组4米', '福彩3D', 2.0);
if (count($valueGroup) !== 2
    || array_filter($valueGroup, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($valueGroup, 'play_type') !== ['直', '组六']
    || array_column($valueGroup, 'amount') !== ['2.00', '2.00']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $valueGroup)) - 4.0) > 0.001) {
    fwrite(STDERR, "Failed: value-group one multiplier shorthand\n" . json_encode($valueGroup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$mismatchedStandaloneTotal = $parser->parse("福358.357.378复试直选一倍\n合106", '福彩3D', 2.0);
if (($mismatchedStandaloneTotal[0]['status'] ?? '') !== 'failed'
    || ($mismatchedStandaloneTotal[0]['reason'] ?? '') !== '整张总金额与识别金额不一致') {
    fwrite(STDERR, "Failed: mismatched standalone total guard\n" . json_encode($mismatchedStandaloneTotal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$compositeDirectBreakdown = $parser->parse('福358.357.378复试直选一倍', '福彩3D', 2.0);
if (($compositeDirectBreakdown[0]['amount_breakdown']['复式'] ?? '') !== '30.00'
    || ($compositeDirectBreakdown[0]['amount_breakdown']['组选'] ?? '') !== '6.00'
    || ($compositeDirectBreakdown[0]['amount'] ?? '') !== '36.00') {
    fwrite(STDERR, "Failed: composite direct amount breakdown\n" . json_encode($compositeDirectBreakdown, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$complete106 = $parser->parse("福\n986.976.086.680.036.673.486.476.046.886.686.996.696.776.676.638直组各一米\n\n体184.148.174.714.967.986.784.014.064.917.911.991.744.774.044.767.117.177.491直组各一米\n\n福358.357.378复试直选一倍\n合106", '福彩3D', 2.0);
if (count($complete106) !== 5
    || array_filter($complete106, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $complete106)) - 106.0) > 0.001
    || ($complete106[array_key_last($complete106)]['amount'] ?? '') !== '36.00') {
    fwrite(STDERR, "Failed: complete 106 total sample\n" . json_encode($complete106, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$prefixedMultilineCounted = $parser->parse("福\n611 661 600 636 336 446 646 033 330 645\n641 631 695 396 693\n697 619 691 三单两组共180米", '福彩3D', 2.0);
if (count($prefixedMultilineCounted) !== 3
    || array_filter($prefixedMultilineCounted, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($prefixedMultilineCounted, 'play_type') !== ['直', '组三', '组六']
    || array_column($prefixedMultilineCounted, 'amount') !== ['108.00', '36.00', '36.00']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $prefixedMultilineCounted)) - 180.0) > 0.001) {
    fwrite(STDERR, "Failed: standalone prefix multiline counted direct/group\n" . json_encode($prefixedMultilineCounted, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$fortyNoticeBatch = $parser->parse("福直组各1米共40注\n813 881 683 868\n840 908 485 589\n168 388 508 498\n301 801 306 680\n004 045 590 934\n160 380 500 409\n135 518 365 685\n540 509 545 955\n165 853 550 495\n515 133 183 633\n🈴80", '福彩3D', 2.0);
if (count($fortyNoticeBatch) !== 3
    || array_filter($fortyNoticeBatch, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($fortyNoticeBatch, 'play_type') !== ['直', '组六', '组三']
    || array_column($fortyNoticeBatch, 'amount') !== ['40.00', '29.00', '11.00']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $fortyNoticeBatch)) - 80.0) > 0.001) {
    fwrite(STDERR, "Failed: forty-notice multiline direct/group batch\n" . json_encode($fortyNoticeBatch, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$meterStakeNoDefaultTwo = $parser->parse("福直组各1米共40注\n813 881 683 868\n840 908 485 589\n168 388 508 498\n301 801 306 680\n004 045 590 934\n160 380 500 409\n135 518 365 685\n540 509 545 955\n165 853 550 495\n515 133 183 633\n🈴80", '福彩3D', 2.0);
if (array_filter($meterStakeNoDefaultTwo, static fn(array $row): bool => in_array(($row['amount'] ?? ''), ['80.00', '58.00', '22.00'], true))) {
    fwrite(STDERR, "Failed: meter stake was replaced by default two-yuan stake\n" . json_encode($meterStakeNoDefaultTwo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$ellipsisDirectGroup = $parser->parse('福彩514.504.534.201.801.154.514.415.120直组各1元……18', '福彩3D', 2.0);
if (count($ellipsisDirectGroup) !== 2
    || array_filter($ellipsisDirectGroup, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($ellipsisDirectGroup, 'play_type') !== ['直', '组六']
    || array_column($ellipsisDirectGroup, 'amount') !== ['9.00', '9.00']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $ellipsisDirectGroup)) - 18.0) > 0.001) {
    fwrite(STDERR, "Failed: ellipsis direct/group total\n" . json_encode($ellipsisDirectGroup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$arithmeticGroupedDrag = $parser->parse("福870.710.801.578.805.321.215.237.538.235.432.329.942.345.456.743.712.713.714.716.718.421.431.631.641.614.634.613.221一单一组116\n\n福01278组六30\n\n福34578组六30\n\n福4拖1236组六20\n\n福6拖1234组六20\n🈴116+30+30+40=216", '福彩3D', 2.0);
if (count($arithmeticGroupedDrag) !== 7
    || array_filter($arithmeticGroupedDrag, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $arithmeticGroupedDrag)) - 216.0) > 0.001
    || array_slice(array_column($arithmeticGroupedDrag, 'amount'), -2) !== ['20.00', '20.00']) {
    fwrite(STDERR, "Failed: arithmetic summary with grouped drag tickets\n" . json_encode($arithmeticGroupedDrag, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$singleChallengeMultiline = $parser->parse("003 009 023 029 083 089 303 309 323 329 383 389 403 409 423 429 483 489 803 809 823 829 883 889 903 909 923 929 983 989\n447 457 467 474 475 476 477 547 574 647 674 744 745 746 747 754 764 774\n福彩单挑1米的\n合48米", '福彩3D', 2.0);
if (count($singleChallengeMultiline) !== 1
    || ($singleChallengeMultiline[0]['status'] ?? '') !== 'success'
    || ($singleChallengeMultiline[0]['play_type'] ?? '') !== '直'
    || ($singleChallengeMultiline[0]['count'] ?? 0) !== 48
    || ($singleChallengeMultiline[0]['amount'] ?? '') !== '48.00') {
    fwrite(STDERR, "Failed: multiline single-challenge direct batch\n" . json_encode($singleChallengeMultiline, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$groupSixOneMultiplier = $parser->parse('02349福组六一倍，合十', '福彩3D', 2.0);
if (count($groupSixOneMultiplier) !== 1
    || ($groupSixOneMultiplier[0]['status'] ?? '') !== 'success'
    || ($groupSixOneMultiplier[0]['play_type'] ?? '') !== '组六五码'
    || ($groupSixOneMultiplier[0]['amount'] ?? '') !== '10.00') {
    fwrite(STDERR, "Failed: five-digit group-six one multiplier\n" . json_encode($groupSixOneMultiplier, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$multiCodeLineAmounts = $parser->parse("福123456组三各1000米\n福0123456组六各500米", '福彩3D', 2.0);
if (count($multiCodeLineAmounts) !== 2
    || array_filter($multiCodeLineAmounts, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($multiCodeLineAmounts, 'amount') !== ['1000.00', '500.00']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $multiCodeLineAmounts)) - 1500.0) > 0.001) {
    fwrite(STDERR, "Failed: separate multi-code line amounts\n" . json_encode($multiCodeLineAmounts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$multiCodeLineTotalAmounts = $parser->parse("福123456组三共1000米\n福0123456组六共500米", '福彩3D', 2.0);
if (count($multiCodeLineTotalAmounts) !== 2
    || array_filter($multiCodeLineTotalAmounts, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($multiCodeLineTotalAmounts, 'amount') !== ['1000.00', '500.00']) {
    fwrite(STDERR, "Failed: 共 alias for separate multi-code amounts\n" . json_encode($multiCodeLineTotalAmounts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$cornerAmount = $parser->parse('014 041 104 114 124 134 140 141 142 143 144 145 146 147 148 149 154 164 174 184 194 214 241 314 341 401 410 411 412 413 414 415 416 417 418 419 421 431 441 451 461 471 481 491 514 541 614 641 714 741 814 841 914 941直2角福54注合10.8', '福彩3D', 2.0);
if (count($cornerAmount) !== 1 || ($cornerAmount[0]['status'] ?? '') !== 'success' || ($cornerAmount[0]['amount'] ?? '') !== '10.80' || ($cornerAmount[0]['count'] ?? 0) !== 54) {
    fwrite(STDERR, "Failed: 2-jiao 54-stake direct amount\n" . json_encode($cornerAmount, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$threeHundredTwentyEightNumbers = implode(' ', array_map(static fn(int $number): string => str_pad((string)$number, 3, '0', STR_PAD_LEFT), range(0, 327)));
$numbersTotalThenPlay = $parser->parse($threeHundredTwentyEightNumbers."\n🈴328米\n福直一米", '福彩3D', 2.0);
if (count($numbersTotalThenPlay) !== 1
    || ($numbersTotalThenPlay[0]['status'] ?? '') !== 'success'
    || ($numbersTotalThenPlay[0]['play_type'] ?? '') !== '直'
    || ($numbersTotalThenPlay[0]['count'] ?? 0) !== 328
    || ($numbersTotalThenPlay[0]['amount'] ?? '') !== '328.00') {
    fwrite(STDERR, "Failed: numbers total before play ordering\n" . json_encode($numbersTotalThenPlay, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$arithmeticDirectGroup = $parser->parse('123 456 789共3注组直各0.2米3*0.4=1.2', '福彩3D', 2.0);
if (count($arithmeticDirectGroup) !== 2
    || array_filter($arithmeticDirectGroup, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($arithmeticDirectGroup, 'play_type') !== ['直', '组']
    || array_column($arithmeticDirectGroup, 'amount') !== ['0.60', '0.60']) {
    fwrite(STDERR, "Failed: arithmetic direct-group count amount\n" . json_encode($arithmeticDirectGroup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$mismatchedNineNumberDirectGroup = $parser->parse("福\n506 515 272 191 536 513 316 516 503\n直组各2米计40米", '福彩3D', 2.0);
if (($mismatchedNineNumberDirectGroup[0]['status'] ?? '') !== 'failed'
    || ($mismatchedNineNumberDirectGroup[0]['reason'] ?? '') !== '整张总金额与识别金额不一致'
    || ($mismatchedNineNumberDirectGroup[0]['suggested_amount'] ?? '') !== '36.00'
    || !str_contains((string)($mismatchedNineNumberDirectGroup[0]['corrected_text'] ?? ''), '计36米')) {
    fwrite(STDERR, "Failed: mismatched nine-number direct/group total must fail\n" . json_encode($mismatchedNineNumberDirectGroup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$confirmedNineNumberDirectGroup = $parser->parse((string)$mismatchedNineNumberDirectGroup[0]['corrected_text'], '福彩3D', 2.0);
if (array_filter($confirmedNineNumberDirectGroup, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $confirmedNineNumberDirectGroup)) - 36.0) > 0.001) {
    fwrite(STDERR, "Failed: manually confirmed corrected total\n" . json_encode($confirmedNineNumberDirectGroup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$randomOrderOneGroup = $parser->parse("872.852各一直一组一元\n286组一元\n福  🈴5米", '福彩3D', 2.0);
if (count($randomOrderOneGroup) !== 3
    || array_filter($randomOrderOneGroup, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($randomOrderOneGroup, 'amount') !== ['2.00', '2.00', '1.00']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $randomOrderOneGroup)) - 5.0) > 0.001) {
    fwrite(STDERR, "Failed: random-order one-group ticket\n" . json_encode($randomOrderOneGroup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$validNineNumberDirectGroup = $parser->parse("福\n506 515 272 191 536 513 316 516 503\n直组各2米计36米", '福彩3D', 2.0);
if (count($validNineNumberDirectGroup) !== 3
    || array_filter($validNineNumberDirectGroup, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $validNineNumberDirectGroup)) - 36.0) > 0.001) {
    fwrite(STDERR, "Failed: valid nine-number direct/group total\n" . json_encode($validNineNumberDirectGroup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$multiplierAmountCases = [
    ['福123直1倍', '2.00'], ['福123组六1倍', '2.00'], ['福123复式1倍', '10.00'],
    ['福12345组六1倍', '10.00'], ['福跨度2 1倍', '10.00'], ['福和值15 1倍', '10.00'],
    ['福和大1倍', '10.00'], ['福对子全包1倍', '10.00'], ['福豹子全包1倍', '20.00'],
    ['福组六1胆234拖各1倍', '10.00'], ['福组六12胆345拖各1倍', '6.00'],
    ['福6拖01234单选全胆拖各1倍', '182.00'], ['福沾边赖012345组三1倍', '158.00'],
    ['福沾边赖0123456组六1倍', '238.00'], ['福单选组六复式01234567 1倍', '672.00'],
    ['福单选组三复式01234567 1倍', '336.00'],
];
foreach ($multiplierAmountCases as [$source, $expected]) {
    $rows = $parser->parse($source, '福彩3D', 2.0);
    if (count($rows) !== 1 || ($rows[0]['status'] ?? '') !== 'success' || ($rows[0]['amount'] ?? '') !== $expected) {
        fwrite(STDERR, "Failed multiplier amount case: {$source}\n" . json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
        exit(1);
    }
}

$amountBeforeDirectGroup = $parser->parse("福907.043.143.179.643.743.943.543.079.679.858.856.888一米直组\n🈴26", '福彩3D', 2.0);
if (count($amountBeforeDirectGroup) !== 4
    || array_filter($amountBeforeDirectGroup, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($amountBeforeDirectGroup, 'play_type') !== ['直', '组', '组', '组']
    || array_column($amountBeforeDirectGroup, 'amount') !== ['13.00', '1.00', '1.00', '11.00']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $amountBeforeDirectGroup)) - 26.0) > 0.001) {
    fwrite(STDERR, "Failed: amount-before-direct-group with leopard\n" . json_encode($amountBeforeDirectGroup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$wrongAmountBeforeDirectGroup = $parser->parse("福907.043.143.179.643.743.943.543.079.679.858.856.888一米直组\n🈴48", '福彩3D', 2.0);
if (($wrongAmountBeforeDirectGroup[0]['status'] ?? '') !== 'failed'
    || ($wrongAmountBeforeDirectGroup[0]['reason'] ?? '') !== '整张总金额与识别金额不一致') {
    fwrite(STDERR, "Failed: amount-before-direct-group mismatch guard\n" . json_encode($wrongAmountBeforeDirectGroup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$bareCountTotal = $parser->parse('两单一组079 407 12', '福彩3D', 2.0);
if (count($bareCountTotal) !== 2
    || array_filter($bareCountTotal, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $bareCountTotal)) - 12.0) > 0.001) {
    fwrite(STDERR, "Failed: bare count-before-list total\n" . json_encode($bareCountTotal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$splitDirect = $parser->parse("福直\n123 456\n10元", '福彩3D', 2.0);
if (count($splitDirect) !== 1
    || ($splitDirect[0]['status'] ?? null) !== 'success'
    || ($splitDirect[0]['play_type'] ?? null) !== '直'
    || ($splitDirect[0]['number_text'] ?? null) !== '123直 456直'
    || ($splitDirect[0]['settlement_text'] ?? null) !== '123 456 直各10.00元 福') {
    fwrite(STDERR, "Failed: split direct canonicalization\n" . json_encode($splitDirect, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$countedDirectGroup = $parser->parse('体895一单五组12', '排列三', 2.0);
if (count($countedDirectGroup) !== 2
    || ($countedDirectGroup[0]['play_type'] ?? null) !== '直'
    || ($countedDirectGroup[0]['amount'] ?? null) !== '2.00'
    || ($countedDirectGroup[1]['play_type'] ?? null) !== '组六'
    || ($countedDirectGroup[1]['stake_count'] ?? 0) !== 5
    || ($countedDirectGroup[1]['amount'] ?? null) !== '10.00') {
    fwrite(STDERR, "Failed: counted direct/group stakes\n" . json_encode($countedDirectGroup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$shortCountedDirectGroup = $parser->parse('体89一单五组60', '排列三', 2.0);
if (count($shortCountedDirectGroup) !== 2
    || ($shortCountedDirectGroup[0]['amount'] ?? null) !== '10.00'
    || ($shortCountedDirectGroup[1]['amount'] ?? null) !== '50.00') {
    fwrite(STDERR, "Failed: non-three-digit counted stakes\n" . json_encode($shortCountedDirectGroup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$listedCountedDirectGroup = $parser->parse('福418 403 901 406各四单一组计40', '福彩3D', 2.0);
if (count($listedCountedDirectGroup) !== 2
    || ($listedCountedDirectGroup[0]['amount'] ?? null) !== '32.00'
    || ($listedCountedDirectGroup[0]['stake_count'] ?? 0) !== 16
    || ($listedCountedDirectGroup[1]['play_type'] ?? null) !== '组六'
    || ($listedCountedDirectGroup[1]['amount'] ?? null) !== '8.00'
    || ($listedCountedDirectGroup[1]['stake_count'] ?? 0) !== 4) {
    fwrite(STDERR, "Failed: listed counted direct/group stakes\n" . json_encode($listedCountedDirectGroup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$listedFortyDirectGroup = $parser->parse('福618 158各四十单一组', '福彩3D', 2.0);
if (count($listedFortyDirectGroup) !== 2
    || ($listedFortyDirectGroup[0]['amount'] ?? null) !== '160.00'
    || ($listedFortyDirectGroup[0]['stake_count'] ?? 0) !== 80
    || ($listedFortyDirectGroup[1]['amount'] ?? null) !== '4.00'
    || ($listedFortyDirectGroup[1]['stake_count'] ?? 0) !== 2) {
    fwrite(STDERR, "Failed: Chinese forty direct/group stakes\n" . json_encode($listedFortyDirectGroup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$invalidChineseHundred = $parser->parse('福618 158各四百单一组', '福彩3D', 2.0);
if (($invalidChineseHundred[0]['status'] ?? null) !== 'failed'
    || !str_contains((string)($invalidChineseHundred[0]['reason'] ?? ''), '中文百位数')) {
    fwrite(STDERR, "Failed: invalid Chinese hundred stake message\n" . json_encode($invalidChineseHundred, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$flyAlias = $parser->parse('福09飞各1元', '福彩3D', 2.0);
if (($flyAlias[0]['status'] ?? null) !== 'success'
    || ($flyAlias[0]['play_type'] ?? null) !== '双飞'
    || ($flyAlias[0]['amount'] ?? null) !== '1.00') {
    fwrite(STDERR, "Failed: 飞 alias for 双飞\n" . json_encode($flyAlias, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$pairFlyClassification = $parser->parse('福77双飞各1元', '福彩3D', 2.0);
if (($pairFlyClassification[0]['play_type'] ?? null) !== '双飞'
    || ($pairFlyClassification[0]['settlement_text'] ?? '') !== '77 双飞 福') {
    fwrite(STDERR, "Failed: repeated 双飞 digits classify as 对子\n" . json_encode($pairFlyClassification, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$groupTenCode = $parser->parse('0123456789组三各1元', '福彩3D', 2.0);
if (($groupTenCode[0]['play_type'] ?? null) !== '组三全包') {
    fwrite(STDERR, "Failed: ten-digit 组三 full package mapping\n" . json_encode($groupTenCode, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$compactTwoCode = $parser->parse('福34组三100米', '福彩3D', 2.0);
if (($compactTwoCode[0]['play_type'] ?? null) !== '组三两码') {
    fwrite(STDERR, "Failed: compact two-digit 组三 mapping\n" . json_encode($compactTwoCode, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$spanAlias = $parser->parse('福跨2各10000米', '福彩3D', 2.0);
$dantuoAliases = [$parser->parse('福1胆拖23各1000米', '福彩3D', 2.0)[0] ?? null, $parser->parse('福胆1拖23各1000米', '福彩3D', 2.0)[0] ?? null];
if (($spanAlias[0]['play_type'] ?? null) !== '跨度2' || ($dantuoAliases[0]['status'] ?? null) !== 'failed' || ($dantuoAliases[1]['status'] ?? null) !== 'failed' || ($parser->parse('福组六1胆拖23各1000米', '福彩3D', 2.0)[0]['play_type'] ?? null) !== '1码拖2') {
    fwrite(STDERR, "Failed: span/dantuo aliases\n" . json_encode([$spanAlias,$dantuoAliases], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$ordinaryFlyClassification = $parser->parse('福37飞各1元', '福彩3D', 2.0);
if (($ordinaryFlyClassification[0]['play_type'] ?? null) !== '双飞'
    || ($ordinaryFlyClassification[0]['settlement_text'] ?? '') !== '37 双飞 福') {
    fwrite(STDERR, "Failed: distinct 飞 digits classify as 双飞\n" . json_encode($ordinaryFlyClassification, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$splitLotteryAndWholeAmount = $parser->parse("福\n百 01456789 十 01456789 个 01456789各 8 元\n\n豹子全包1500 元", '福彩3D');
if (count($splitLotteryAndWholeAmount) !== 2 || ($splitLotteryAndWholeAmount[0]['status'] ?? null) !== 'success' || ($splitLotteryAndWholeAmount[0]['count'] ?? 0) !== 512 || ($splitLotteryAndWholeAmount[0]['amount'] ?? null) !== '4096.00' || ($splitLotteryAndWholeAmount[1]['status'] ?? null) !== 'success' || ($splitLotteryAndWholeAmount[1]['amount'] ?? null) !== '1500.00') {
    fwrite(STDERR, "Failed: split lottery and whole amount\n" . json_encode($splitLotteryAndWholeAmount, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$mixedDirectAndGroup = $parser->parse('571 574 579 570四十倍直十倍组共400元福', '福彩3D');
if (count($mixedDirectAndGroup) !== 2
    || ($mixedDirectAndGroup[0]['status'] ?? null) !== 'success'
    || ($mixedDirectAndGroup[0]['play_type'] ?? null) !== '直'
    || ($mixedDirectAndGroup[0]['count'] ?? 0) !== 4
    || ($mixedDirectAndGroup[0]['amount'] ?? null) !== '320.00'
    || ($mixedDirectAndGroup[1]['play_type'] ?? null) !== '组六'
    || ($mixedDirectAndGroup[1]['count'] ?? 0) !== 4
    || ($mixedDirectAndGroup[1]['amount'] ?? null) !== '80.00') {
    fwrite(STDERR, "Failed: mixed direct and group\n" . json_encode($mixedDirectAndGroup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$compactDirectGroup = $parser->parse('体478 487 784 748 874 847 457 475 547 574 745 754 五单一组144元', '排列三', 2.0);
if (count($compactDirectGroup) !== 2
    || array_filter($compactDirectGroup, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($compactDirectGroup, 'play_type') !== ['直', '组六']
    || array_column($compactDirectGroup, 'count') !== [12, 2]
    || array_column($compactDirectGroup, 'amount') !== ['120.00', '24.00']
    || ($compactDirectGroup[1]['number_text'] ?? '') !== '478组 457组'
    || array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $compactDirectGroup)) !== 144.0) {
    fwrite(STDERR, "Failed: compact direct/group total syntax\n" . json_encode($compactDirectGroup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$shortDirect = $parser->parse('629 269直10', '福彩3D');
if (($shortDirect[0]['status'] ?? null) !== 'success' || ($shortDirect[0]['count'] ?? 0) !== 2 || ($shortDirect[0]['amount'] ?? null) !== '20.00') {
    fwrite(STDERR, "Failed: short direct amount\n" . json_encode($shortDirect, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$multiplierDirect = $parser->parse('福直 936 639 926 629 693 692 3倍 共36', '福彩3D');
if (($multiplierDirect[0]['status'] ?? null) !== 'success' || ($multiplierDirect[0]['count'] ?? 0) !== 6 || ($multiplierDirect[0]['amount'] ?? null) !== '36.00') {
    fwrite(STDERR, "Failed: direct multiplier\n" . json_encode($multiplierDirect, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$customUnitStake = $parser->parse('福直 936 639 926 629 693 692 3倍 共54', '福彩3D', 3.0);
if (($customUnitStake[0]['status'] ?? null) !== 'success' || ($customUnitStake[0]['amount'] ?? null) !== '54.00') {
    fwrite(STDERR, "Failed: custom lottery unit stake\n" . json_encode($customUnitStake, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$positionMultiplier = $parser->parse('百23568 十13468 个23468各20倍共计5000元', '福彩3D', 2.0);
if (($positionMultiplier[0]['status'] ?? null) !== 'success' || ($positionMultiplier[0]['count'] ?? 0) !== 125 || ($positionMultiplier[0]['amount'] ?? null) !== '5000.00') {
    fwrite(STDERR, "Failed: position multiplier unit stake\n" . json_encode($positionMultiplier, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$thousandNumbers = implode(' ', array_map(static fn(int $number): string => str_pad((string)$number, 3, '0', STR_PAD_LEFT), range(0, 999))).'直10';
$longDirect = $parser->parse($thousandNumbers, '福彩3D');
if (($longDirect[0]['status'] ?? null) !== 'success' || ($longDirect[0]['count'] ?? 0) !== 1000 || ($longDirect[0]['amount'] ?? null) !== '10000.00') {
    fwrite(STDERR, "Failed: long direct list\n" . json_encode($longDirect[0] ?? null, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$duplicateDirect = $parser->parse('668 668 123直30', '福彩3D', 2.0);
if (($duplicateDirect[0]['status'] ?? null) !== 'success' || ($duplicateDirect[0]['count'] ?? 0) !== 2 || ($duplicateDirect[0]['stake_count'] ?? 0) !== 3 || ($duplicateDirect[0]['amount'] ?? null) !== '90.00') {
    fwrite(STDERR, "Failed: duplicate direct list\n" . json_encode($duplicateDirect, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$fuTiNumbers = implode(' ', array_map(static fn(int $number): string => str_pad((string)$number, 3, '0', STR_PAD_LEFT), range(0, 427))).' 668 668';
$fuTiDuplicate = $parser->parse($fuTiNumbers.'福体直30', '福彩3D', 2.0);
if (($fuTiDuplicate[0]['status'] ?? null) !== 'success' || ($fuTiDuplicate[0]['count'] ?? 0) !== 858 || ($fuTiDuplicate[0]['stake_count'] ?? 0) !== 860 || ($fuTiDuplicate[0]['amount'] ?? null) !== '25800.00') {
    fwrite(STDERR, "Failed: duplicate Fu/Ti direct list\n" . json_encode($fuTiDuplicate[0] ?? null, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$summaryDirect = $parser->parse($fuTiNumbers.'福430注直30', '福彩3D', 2.0);
if (($summaryDirect[0]['status'] ?? null) !== 'success' || ($summaryDirect[0]['count'] ?? 0) !== 429 || ($summaryDirect[0]['stake_count'] ?? 0) !== 430 || ($summaryDirect[0]['code_count'] ?? 0) !== 429 || ($summaryDirect[0]['amount'] ?? null) !== '25800.00') {
    fwrite(STDERR, "Failed: summary direct list\n" . json_encode($summaryDirect[0] ?? null, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$batchLines = [];
$batchNumbers = array_map(static fn(int $number): string => str_pad((string)$number, 3, '0', STR_PAD_LEFT), range(0, 429));
$batchNumbers[428] = '668';
$batchNumbers[429] = '668';
foreach (array_chunk($batchNumbers, 10) as $batchChunk) {
    $batchLines[] = implode('，', $batchChunk);
}
$batchText = implode("\n", $batchLines).'福430注直30';
$batch = $parser->parse($batchText, '福彩3D', 2.0);
if (count($batch) !== 43
    || array_filter($batch, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_sum(array_map(static fn(array $row): int => (int)($row['code_count'] ?? 0), $batch)) !== 429
    || array_sum(array_map(static fn(array $row): float => (float)($row['amount'] ?? 0), $batch)) !== 25800.0
    || ($batch[0]['raw_text'] ?? '') !== $batchLines[0]
    || !str_contains((string)($batch[0]['parse_text'] ?? ''), '10注直30')
    || !str_contains((string)($batch[42]['raw_text'] ?? ''), '福430注直30')
    || ($batch[0]['batch_id'] ?? '') === ''
    || count(array_unique(array_column($batch, 'batch_id'))) !== 1
    || ($batch[0]['batch_index'] ?? 0) !== 1
    || ($batch[0]['batch_end'] ?? true) !== false
    || ($batch[42]['batch_index'] ?? 0) !== 43
    || ($batch[42]['batch_size'] ?? 0) !== 43
    || ($batch[42]['batch_end'] ?? false) !== true
    || ($batch[42]['batch_count'] ?? 0) !== 429
    || ($batch[42]['batch_stake_count'] ?? 0) !== 430
    || ($batch[42]['batch_amount'] ?? null) !== '25800.00'
    || count(preg_split('/\s+/', trim((string)($batch[42]['batch_number_text'] ?? ''))) ?: []) !== 429
    || count(preg_split('/\s+/', trim((string)($batch[42]['batch_occurrence_text'] ?? ''))) ?: []) !== 430
    || str_contains((string)($batch[42]['batch_merged_text'] ?? ''), "\n")
    || !str_ends_with((string)($batch[42]['batch_merged_text'] ?? ''), '福430注直30')
    || substr_count((string)($batch[42]['batch_merged_text'] ?? ''), '福430注直30') !== 1
    || ($batch[42]['batch_has_duplicates'] ?? false) !== true
    || ($batch[42]['batch_duplicate_numbers'] ?? []) !== ['668']) {
    fwrite(STDERR, "Failed: per-line direct batch\n" . json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

// A pasted/replaced number list may retain the old declared N in its suffix.
// The explicit suffix still marks a physical-line batch; it must not collapse
// back into one wrapped row.
$replacedBatch = $parser->parse("111，222，333\n444，555，666福430注直30", '福彩3D', 2.0);
if (count($replacedBatch) !== 2
    || ($replacedBatch[0]['batch_index'] ?? 0) !== 1
    || ($replacedBatch[1]['batch_end'] ?? false) !== true
    || ($replacedBatch[1]['batch_count'] ?? 0) !== 6
    || ($replacedBatch[1]['batch_declared_stake_count'] ?? 0) !== 430
    || ($replacedBatch[1]['batch_count_mismatch'] ?? false) !== true
    || !str_ends_with((string)($replacedBatch[1]['batch_merged_text'] ?? ''), '福6注直30')) {
    fwrite(STDERR, "Failed: replaced number batch should stay split\n" . json_encode($replacedBatch, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

// The shared suffix can also be wrapped onto its own physical line.
$separateSuffixBatch = $parser->parse("111，222，333\n444，555，666\n福6注直30", '福彩3D', 2.0);
if (count($separateSuffixBatch) !== 2
    || ($separateSuffixBatch[1]['batch_end'] ?? false) !== true
    || !str_contains((string)($separateSuffixBatch[1]['raw_text'] ?? ''), '福6注直30')) {
    fwrite(STDERR, "Failed: separate suffix batch should stay split\n" . json_encode($separateSuffixBatch, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

// The suffix aliases and punctuation are normalized before the batch marker
// is inspected, so changing the number text does not collapse the rows.
$normalizedSuffixBatch = $parser->parse("111,222,333\n444、555、666福6注直选30", '福彩3D', 2.0);
if (count($normalizedSuffixBatch) !== 2
    || count(array_unique(array_column($normalizedSuffixBatch, 'batch_id'))) !== 1
    || ($normalizedSuffixBatch[1]['batch_end'] ?? false) !== true
    || ($normalizedSuffixBatch[1]['batch_count'] ?? 0) !== 6
    || ($normalizedSuffixBatch[1]['batch_count_mismatch'] ?? true) !== false) {
    fwrite(STDERR, "Failed: normalized suffix batch\n" . json_encode($normalizedSuffixBatch, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

// Multiple marked tickets and an ordinary line may coexist in one paste;
// each marker owns only the contiguous number lines immediately before it.
$multipleBatches = $parser->parse("123直2\n111，222\n333，444福4注直30\n555，666\n777，888福4注直20", '福彩3D', 2.0);
if (count($multipleBatches) !== 5
    || ($multipleBatches[0]['batch_id'] ?? null) !== null
    || ($multipleBatches[1]['batch_end'] ?? true) !== false
    || ($multipleBatches[2]['batch_end'] ?? false) !== true
    || ($multipleBatches[3]['batch_end'] ?? true) !== false
    || ($multipleBatches[4]['batch_end'] ?? false) !== true
    || count(array_unique(array_filter(array_column($multipleBatches, 'batch_id')))) !== 2) {
    fwrite(STDERR, "Failed: multiple explicit batches\n" . json_encode($multipleBatches, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

// Without an explicit N注 marker, physical lines remain independent parse
// attempts; the parser must not invent a shared batch or amount.
$withoutMarker = $parser->parse("111，222\n333，444", '福彩3D', 2.0);
if (count($withoutMarker) !== 2
    || array_filter($withoutMarker, static fn(array $row): bool => ($row['batch_id'] ?? null) !== null)
    || array_filter($withoutMarker, static fn(array $row): bool => ($row['status'] ?? '') !== 'failed')) {
    fwrite(STDERR, "Failed: unmarked physical lines\n" . json_encode($withoutMarker, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$explicitMoneyDirect = $parser->parse('629 269直10元', '福彩3D');
if (($explicitMoneyDirect[0]['status'] ?? null) !== 'success' || ($explicitMoneyDirect[0]['amount'] ?? null) !== '20.00') {
    fwrite(STDERR, "Failed: explicit direct money\n" . json_encode($explicitMoneyDirect, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$wrappedDirectGroup = $parser->parse("052 560 725 253 526\n285 279 982 287 764\n247 50倍单50组共计2200元", '福彩3D', 2.0);
if (count($wrappedDirectGroup) !== 2
    || ($wrappedDirectGroup[0]['status'] ?? null) !== 'success'
    || ($wrappedDirectGroup[0]['play_type'] ?? null) !== '直'
    || ($wrappedDirectGroup[0]['count'] ?? 0) !== 11
    || ($wrappedDirectGroup[0]['amount'] ?? null) !== '1100.00'
    || ($wrappedDirectGroup[1]['play_type'] ?? null) !== '组六'
    || ($wrappedDirectGroup[1]['count'] ?? 0) !== 11
    || ($wrappedDirectGroup[1]['amount'] ?? null) !== '1100.00') {
    fwrite(STDERR, "Failed: wrapped direct/group ticket\n" . json_encode($wrappedDirectGroup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$digitSetGroups = $parser->parse('25789 1000元组六600组三共计1600元', '福彩3D', 2.0);
if (count($digitSetGroups) !== 2
    || ($digitSetGroups[0]['status'] ?? null) !== 'success'
    || ($digitSetGroups[0]['play_type'] ?? null) !== '组六五码'
    || ($digitSetGroups[0]['count'] ?? 0) !== 1
    || ($digitSetGroups[0]['amount'] ?? null) !== '1000.00'
    || ($digitSetGroups[0]['number_text'] ?? null) !== '257 258 259 278 279 289 578 579 589 789'
    || ($digitSetGroups[1]['play_type'] ?? null) !== '组三五码'
    || ($digitSetGroups[1]['count'] ?? 0) !== 1
    || ($digitSetGroups[1]['amount'] ?? null) !== '600.00'
    || ($digitSetGroups[1]['number_text'] ?? null) !== '225 227 228 229 552 557 558 559 772 775 778 779 882 885 887 889 992 995 997 998') {
    fwrite(STDERR, "Failed: digit-set group ticket\n" . json_encode($digitSetGroups, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$splitGroupMultipliers = $parser->parse("23479 -24578两倍组三\n一倍组六  60", '福彩3D', 2.0);
if (count($splitGroupMultipliers) !== 4
    || array_filter($splitGroupMultipliers, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($splitGroupMultipliers, 'play_type') !== ['组三五码', '组三五码', '组六五码', '组六五码']
    || array_column($splitGroupMultipliers, 'number_text') !== ['三23479', '三24578', '六23479', '六24578']
    || array_column($splitGroupMultipliers, 'amount') !== ['20.00', '20.00', '10.00', '10.00']
    || array_column($splitGroupMultipliers, 'multiplier') !== [2.0, 2.0, 1.0, 1.0]
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $splitGroupMultipliers)) - 60.0) > 0.001) {
    fwrite(STDERR, "Failed: split mixed group multipliers\n" . json_encode($splitGroupMultipliers, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$touchingGroupMultipliers = $parser->parse('23479-24578两倍组三一倍组六60', '福彩3D', 2.0);
if (array_column($touchingGroupMultipliers, 'number_text') !== ['三23479', '三24578', '六23479', '六24578']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $touchingGroupMultipliers)) - 60.0) > 0.001) {
    fwrite(STDERR, "Failed: touching mixed group multipliers\n" . json_encode($touchingGroupMultipliers, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$wrongGroupMultiplierTotal = $parser->parse('23479-24578两倍组三一倍组六50', '福彩3D', 2.0);
if (($wrongGroupMultiplierTotal[0]['status'] ?? null) !== 'failed'
    || ($wrongGroupMultiplierTotal[0]['reason'] ?? null) !== '句末总金额与组选倍数不一致') {
    fwrite(STDERR, "Failed: mixed group multiplier total validation\n" . json_encode($wrongGroupMultiplierTotal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$explicitCatalogGroup = $parser->parse('15862组六五码108元', '福彩3D', 2.0);
if (count($explicitCatalogGroup) !== 1
    || ($explicitCatalogGroup[0]['status'] ?? null) !== 'success'
    || ($explicitCatalogGroup[0]['play_type'] ?? null) !== '组六五码'
    || ($explicitCatalogGroup[0]['amount'] ?? null) !== '108.00'
    || ($explicitCatalogGroup[0]['number_text'] ?? null) !== '六15862') {
    fwrite(STDERR, "Failed: explicit catalog group combinations\n" . json_encode($explicitCatalogGroup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$listedFiveDigitGroup = $parser->parse('01467 02467 03467 04567 04678 04679福组六五元 合计30元', '福彩3D', 2.0);
if (count($listedFiveDigitGroup) !== 6
    || array_filter($listedFiveDigitGroup, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($listedFiveDigitGroup, 'amount') !== ['5.00', '5.00', '5.00', '5.00', '5.00', '5.00']
    || array_column($listedFiveDigitGroup, 'play_type') !== ['组六五码', '组六五码', '组六五码', '组六五码', '组六五码', '组六五码']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $listedFiveDigitGroup)) - 30.0) > 0.001) {
    fwrite(STDERR, "Failed: listed five-digit group rows\n" . json_encode($listedFiveDigitGroup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$separateLotteryAndShortPlay = $parser->parse("福\n256，165，10单10组，\n\n体，117，20单", '福彩3D', 2.0);
if (count($separateLotteryAndShortPlay) !== 3
    || ($separateLotteryAndShortPlay[0]['status'] ?? null) !== 'success'
    || ($separateLotteryAndShortPlay[0]['category'] ?? null) !== '福'
    || ($separateLotteryAndShortPlay[0]['play_type'] ?? null) !== '直'
    || ($separateLotteryAndShortPlay[0]['count'] ?? 0) !== 2
    || ($separateLotteryAndShortPlay[0]['amount'] ?? null) !== '40.00'
    || ($separateLotteryAndShortPlay[1]['play_type'] ?? null) !== '组六'
    || ($separateLotteryAndShortPlay[1]['count'] ?? 0) !== 2
    || ($separateLotteryAndShortPlay[1]['amount'] ?? null) !== '40.00'
    || ($separateLotteryAndShortPlay[2]['category'] ?? null) !== '体'
    || ($separateLotteryAndShortPlay[2]['play_type'] ?? null) !== '直'
    || ($separateLotteryAndShortPlay[2]['count'] ?? 0) !== 1
    || ($separateLotteryAndShortPlay[2]['amount'] ?? null) !== '40.00'
    || abs(array_sum(array_map(static fn(array $row):float=>(float)$row['amount'],$separateLotteryAndShortPlay))-120.0)>0.001) {
    fwrite(STDERR, "Failed: separate lottery and short play multiplier\n" . json_encode($separateLotteryAndShortPlay, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$groupAliasesAndGrandTotal = $parser->parse("福，023467，组6，300米，\n\n体123578，组6，400米，组3，100米，🈴800", '福彩3D', 2.0);
if (count($groupAliasesAndGrandTotal) !== 3
    || ($groupAliasesAndGrandTotal[0]['status'] ?? null) !== 'success'
    || ($groupAliasesAndGrandTotal[0]['category'] ?? null) !== '福'
    || ($groupAliasesAndGrandTotal[0]['play_type'] ?? null) !== '组六六码'
    || ($groupAliasesAndGrandTotal[0]['count'] ?? 0) !== 1
    || ($groupAliasesAndGrandTotal[0]['amount'] ?? null) !== '300.00'
    || ($groupAliasesAndGrandTotal[1]['category'] ?? null) !== '体'
    || ($groupAliasesAndGrandTotal[1]['play_type'] ?? null) !== '组六六码'
    || ($groupAliasesAndGrandTotal[1]['count'] ?? 0) !== 1
    || ($groupAliasesAndGrandTotal[1]['amount'] ?? null) !== '400.00'
    || ($groupAliasesAndGrandTotal[2]['play_type'] ?? null) !== '组三六码'
    || ($groupAliasesAndGrandTotal[2]['count'] ?? 0) !== 1
    || ($groupAliasesAndGrandTotal[2]['amount'] ?? null) !== '100.00') {
    fwrite(STDERR, "Failed: group aliases and whole-ticket total\n" . json_encode($groupAliasesAndGrandTotal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$fuTiGroupEach = $parser->parse('15862福体组六各108', '福彩3D', 2.0);
if (count($fuTiGroupEach) !== 1
    || ($fuTiGroupEach[0]['status'] ?? null) !== 'success'
    || ($fuTiGroupEach[0]['category'] ?? null) !== '福体'
    || ($fuTiGroupEach[0]['play_type'] ?? null) !== '组六五码'
    || ($fuTiGroupEach[0]['count'] ?? 0) !== 2
    || ($fuTiGroupEach[0]['amount'] ?? null) !== '216.00'
    || ($fuTiGroupEach[0]['number_text'] ?? null) !== '六15862') {
    fwrite(STDERR, "Failed: Fu/Ti group-six each amount\n" . json_encode($fuTiGroupEach, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$fuTiGroupThreeEach = $parser->parse('15862福体组三各108', '福彩3D', 2.0);
if (count($fuTiGroupThreeEach) !== 1
    || ($fuTiGroupThreeEach[0]['status'] ?? null) !== 'success'
    || ($fuTiGroupThreeEach[0]['category'] ?? null) !== '福体'
    || ($fuTiGroupThreeEach[0]['play_type'] ?? null) !== '组三五码'
    || ($fuTiGroupThreeEach[0]['count'] ?? 0) !== 2
    || ($fuTiGroupThreeEach[0]['amount'] ?? null) !== '216.00'
    || ($fuTiGroupThreeEach[0]['number_text'] ?? null) !== '三15862') {
    fwrite(STDERR, "Failed: Fu/Ti group-three each amount\n" . json_encode($fuTiGroupThreeEach, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$singlePosition = $parser->parse("体 3十 500米\n3胆500米", '福彩3D', 2.0);
if (count($singlePosition) !== 2
    || ($singlePosition[0]['status'] ?? null) !== 'success'
    || ($singlePosition[0]['category'] ?? null) !== '体'
    || ($singlePosition[0]['count'] ?? 0) !== 1
    || ($singlePosition[0]['amount'] ?? null) !== '500.00'
    || ($singlePosition[1]['status'] ?? null) !== 'success'
    || ($singlePosition[1]['amount'] ?? null) !== '500.00') {
    fwrite(STDERR, "Failed: single-position and single-digit play\n" . json_encode($singlePosition, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$formatted = $parser->formatText("福，023467，组6，300米，\n\n体123578，组6，400米，组3，100米，🈴800\n23 34双飞各200米\n体 3十 500米\n3胆500米");
$expectedFormatted = "福 023467 组6 300元\n\n体123578 组6 400元 组3 100元 合800\n23 34双飞各200元\n体 3十 500元\n3胆500元";
if ($formatted !== $expectedFormatted) {
    fwrite(STDERR, "Failed: formatted quick-entry text\nExpected:\n{$expectedFormatted}\nActual:\n{$formatted}\n");
    exit(1);
}

$sizeParity = $parser->parse('体和小50000米', '福彩3D', 2.0);
if (count($sizeParity) !== 1
    || ($sizeParity[0]['status'] ?? null) !== 'success'
    || ($sizeParity[0]['category'] ?? null) !== '体'
    || ($sizeParity[0]['play_type'] ?? null) !== '和小'
    || ($sizeParity[0]['count'] ?? 0) !== 1
    || ($sizeParity[0]['amount'] ?? null) !== '50000.00') {
    fwrite(STDERR, "Failed: sum size/parity bet\n" . json_encode($sizeParity, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$sumSet = $parser->parse('12 20 大 8和值各10米', '福彩3D', 2.0);
if (count($sumSet)!==4 || array_sum(array_map(static fn(array $row):float=>(float)$row['amount'],$sumSet))!==40.0 || array_column($sumSet,'play_type')!==['和值12','和值20','和大','和值8']) {
    fwrite(STDERR,"Failed: sum selection set\n".json_encode($sumSet,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);
}
$spanSet=$parser->parse('1-3-6-7跨各5米','福彩3D',2.0);
if(count($spanSet)!==4||array_sum(array_map(static fn(array $row):float=>(float)$row['amount'],$spanSet))!==20.0){fwrite(STDERR,"Failed: span selection set\n".json_encode($spanSet,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);}

$multiCode=$parser->parse('123456 7890 1789组三各10米组六各20米复式各30米','福彩3D',2.0);
if(count($multiCode)!==9||array_sum(array_map(static fn(array $row):float=>(float)$row['amount'],$multiCode))!==180.0||array_filter($multiCode,static fn(array $row):bool=>($row['status']??'')!=='success')){fwrite(STDERR,"Failed: multi-code play sets\n".json_encode($multiCode,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);}
$multiShared=$parser->parse('组三组六复式各20米123456 7890 1789','福彩3D',2.0);
if(count($multiShared)!==9||array_sum(array_map(static fn(array $row):float=>(float)$row['amount'],$multiShared))!==180.0){fwrite(STDERR,"Failed: shared multi-code amount\n".json_encode($multiShared,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);}
$trialAlias=$parser->parse('福 024567 复试各 1 米','福彩3D',2.0)[0]??null;
if(!is_array($trialAlias)||($trialAlias['status']??'')!=='success'||($trialAlias['number_text']??'')!=='000'||($trialAlias['display_number_text']??'')!=='复024567'||($trialAlias['count']??0)!==1||($trialAlias['amount']??'')!=='1.00'){fwrite(STDERR,"Failed: 复试 alias and single 复式 package\n".json_encode($trialAlias,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);}
$compactFushi=$parser->parse('福01234567复试10米','福彩3D',2.0)[0]??null;
if(!is_array($compactFushi)||($compactFushi['status']??'')!=='success'||($compactFushi['play_type']??'')!=='复式八码'||($compactFushi['number_text']??'')!=='复01234567'||($compactFushi['amount']??'')!=='10.00'){fwrite(STDERR,"Failed: concise 复式 amount syntax\n".json_encode($compactFushi,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);}
$prefixGroup=$parser->parse('福组三023456各1','福彩3D',2.0)[0]??null;
if(!is_array($prefixGroup)||($prefixGroup['status']??'')!=='success'||($prefixGroup['play_type']??'')!=='组三六码'||($prefixGroup['number_text']??'')!=='三023456'){fwrite(STDERR,"Failed: prefix single group syntax\n".json_encode($prefixGroup,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);}
$prefixCatalog=$parser->parse('福组三两码12各10米','福彩3D',2.0)[0]??null;
if(!is_array($prefixCatalog)||($prefixCatalog['status']??'')!=='success'||($prefixCatalog['play_type']??'')!=='组三两码'||($prefixCatalog['number_text']??'')!=='三12'||($prefixCatalog['amount']??'')!=='10.00'){fwrite(STDERR,"Failed: prefix catalog group syntax\n".json_encode($prefixCatalog,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);}
$prefixDrag=$parser->parse('福体组六 9 拖 12718 各 1 倍','福彩3D',2.0)[0]??null;
if(!is_array($prefixDrag)||($prefixDrag['status']??'')!=='success'||($prefixDrag['category']??'')!=='福体'||($prefixDrag['play_type']??'')!=='1码拖4'||($prefixDrag['number_text']??'')!=='胆9拖1278'||($prefixDrag['amount']??'')!=='20.00'){fwrite(STDERR,"Failed: prefix group drag syntax\n".json_encode($prefixDrag,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);}
$defaultPrefixDrag=$parser->parse('福体 9 拖 12718 各 1 倍','福彩3D',2.0)[0]??null;
if(!is_array($defaultPrefixDrag)||($defaultPrefixDrag['status']??'')!=='success'||($defaultPrefixDrag['play_type']??'')!=='1码拖4'||($defaultPrefixDrag['amount']??'')!=='20.00'){fwrite(STDERR,"Failed: default prefix group drag syntax\n".json_encode($defaultPrefixDrag,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);}
$prefixPlay=$parser->parse('福体组六组三 123456 各 1 米','福彩3D',2.0);
if(count($prefixPlay)!==2||array_filter($prefixPlay,static fn(array $row):bool=>($row['status']??'')!=='success')||array_column($prefixPlay,'play_type')!==['组六六码','组三六码']||array_sum(array_map(static fn(array $row):float=>(float)$row['amount'],$prefixPlay))!==4.0){fwrite(STDERR,"Failed: prefix group play order\n".json_encode($prefixPlay,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);}

$partialPosition=$parser->parse('十3456个12345各3米','福彩3D',2.0)[0]??null;
if(!is_array($partialPosition)||($partialPosition['status']??'')!=='success'||($partialPosition['count']??0)!==20||($partialPosition['amount']??'')!=='60.00'){fwrite(STDERR,"Failed: partial position selections\n".json_encode($partialPosition,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);}
$settlementMatcher=new app\service\BetSettlement();
if(!$settlementMatcher->numberMatches('031','931','十3456个12345各3元')||$settlementMatcher->numberMatches('031','941','十3456个12345各3元')){fwrite(STDERR,"Failed: partial position settlement\n");exit(1);}

$singleDrag=$parser->parse('1拖23456 2拖5678组三组六各10米','福彩3D',2.0);
if(count($singleDrag)!==4||array_sum(array_map(static fn(array $row):float=>(float)$row['amount'],$singleDrag))!==40.0||array_column($singleDrag,'play_type')!==['1码拖5','1码拖5','1码拖4','1码拖4']){fwrite(STDERR,"Failed: single banker drag\n".json_encode($singleDrag,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);}
$doubleDrag=$parser->parse('双胆23拖56789 56拖1234各10米','福彩3D',2.0);
if(count($doubleDrag)!==2||array_sum(array_map(static fn(array $row):float=>(float)$row['amount'],$doubleDrag))!==20.0||array_column($doubleDrag,'play_type')!==['2码拖5','2码拖4']){fwrite(STDERR,"Failed: double banker drag\n".json_encode($doubleDrag,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);}

foreach ([
    '跨度0 100元' => '跨度0',
    '和值13 100元' => '和值13',
    '豹子全包100元' => '豹子全包',
    '对子全包100元' => '对子全包',
] as $text => $playType) {
    $row = $parser->parse($text, '福彩3D', 2.0)[0] ?? null;
    if (!is_array($row) || ($row['status'] ?? null) !== 'success' || ($row['play_type'] ?? null) !== $playType || ($row['amount'] ?? null) !== '100.00') {
        fwrite(STDERR, "Failed: conditional outcome {$text}\n" . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
        exit(1);
    }
}

foreach ([
    '12组三两码100元' => '组三两码',
    '1234组六四码100元' => '组六四码',
    '123复式三码100元' => '复式三码',
    '1组三赖一码100元' => '组三赖一码',
    '12组六赖二码100元' => '组六赖二码',
    '组三全包100元' => '组三全包',
    '组六全包100元' => '组六全包',
] as $text => $playType) {
    $row = $parser->parse($text, '福彩3D', 2.0)[0] ?? null;
    if (!is_array($row) || ($row['status'] ?? null) !== 'success' || ($row['play_type'] ?? null) !== $playType || ($row['amount'] ?? null) !== '100.00') {
        fwrite(STDERR, "Failed: catalog play {$text}\n" . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
        exit(1);
    }
}
$fuTiCatalog = $parser->parse('福体12组三两码100元', '福彩3D', 2.0)[0] ?? null;
if (!is_array($fuTiCatalog) || ($fuTiCatalog['status'] ?? null) !== 'success' || ($fuTiCatalog['count'] ?? 0) !== 2 || ($fuTiCatalog['amount'] ?? null) !== '200.00') {
    fwrite(STDERR, "Failed: Fu/Ti catalog amount\n" . json_encode($fuTiCatalog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$fuTiOutcome = $parser->parse('福体豹子全包100元', '福彩3D', 2.0)[0] ?? null;
if (!is_array($fuTiOutcome) || ($fuTiOutcome['status'] ?? null) !== 'success' || ($fuTiOutcome['count'] ?? 0) !== 2 || ($fuTiOutcome['amount'] ?? null) !== '200.00') {
    fwrite(STDERR, "Failed: Fu/Ti outcome amount\n" . json_encode($fuTiOutcome, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$wrongCatalogCount = $parser->parse('123组六四码100元', '福彩3D', 2.0)[0] ?? null;
if (($wrongCatalogCount['status'] ?? null) !== 'failed' || ($wrongCatalogCount['reason'] ?? null) !== '所选数字数量与玩法不一致') {
    fwrite(STDERR, "Failed: catalog selection count validation\n" . json_encode($wrongCatalogCount, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$rules = new app\service\QuickEntryRules();
foreach ([
    '体和小50000元' => ['大小单双', '大小单双'],
    '跨度7 福' => ['跨度', '跨度7'],
    '和值14 福' => ['和值', '和值13-14'],
    '3十 500元 体' => ['一码定位', 'X口X'],
    '234 直各100元 体' => ['三码定位', '三码定位'],
    '3胆500元' => ['独胆', '独胆'],
    '23双飞各200元' => ['双飞', '双飞'],
] as $source => [$category, $name]) {
    $identity = $rules->oddsIdentity($source);
    if (($identity['category'] ?? null) !== $category || ($identity['name'] ?? null) !== $name) {
        fwrite(STDERR, "Failed: odds identity {$source}\n" . json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
        exit(1);
    }
}

$dslCases=[
    'F123单100'=>['福','直','100.00','ZX'],
    'T112Z330'=>['体','组三','30.00','Z3'],
    '123Z620'=>['福','组六','20.00','Z6'],
    '1234包20'=>['福','组六四码','20.00','Z6'],
    '5胆678拖组六20'=>['福','1码拖3','20.00','Z6'],
    '56胆789拖组六20'=>['福','2码拖3','20.00','Z6'],
    'B1 20'=>['福',null,'20.00','1D'],
    '百十12 20'=>['福',null,'20.00','2D'],
    'H15 20'=>['福','和值15','20.00','HZ'],
    'K5 20'=>['福','跨度5','20.00','KD'],
];
foreach($dslCases as $source=>[$category,$playType,$amount,$astPlay]){
    $row=$parser->parse($source,'福彩3D',2.0)[0]??null;
    if(!is_array($row)||($row['status']??'')!=='success'||($row['category']??'')!==$category||($row['amount']??'')!==$amount||($row['ast']['play']??'')!==$astPlay||array_key_exists('user',$row['ast']??[])||($playType!==null&&($row['play_type']??'')!==$playType)){
        fwrite(STDERR,"Failed DSL case: {$source}\n".json_encode($row,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);
    }
}
$defaultFc=$parser->parse('123组六20','福彩3D',2.0)[0]??null;
$defaultPl=$parser->parse('123组六20','排列三',2.0)[0]??null;
$explicitOverride=$parser->parse('体123组六20','福彩3D',2.0)[0]??null;
if(($defaultFc['category']??'')!=='福'||($defaultFc['ast']['lottery']??[])!==['FC3D']||($defaultPl['category']??'')!=='体'||($defaultPl['ast']['lottery']??[])!==['PL3']||($explicitOverride['category']??'')!=='体'||($explicitOverride['ast']['lottery']??[])!==['PL3']){
    fwrite(STDERR,"Failed default lottery precedence\n".json_encode([$defaultFc,$defaultPl,$explicitOverride],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);
}
$implicitDirectCases=[['123','2.00'],['123×50','100.00'],['123x50','100.00'],['123*50','100.00'],['123 50倍','100.00'],['123直×50','100.00']];
foreach($implicitDirectCases as [$source,$expectedAmount]){$row=$parser->parse($source,'福彩3D',2.0)[0]??null;if(!is_array($row)||($row['status']??'')!=='success'||($row['play_type']??'')!=='直'||($row['count']??0)!==1||($row['amount']??'')!==$expectedAmount||($row['ast']['play']??'')!=='ZX'){fwrite(STDERR,"Failed implicit direct: {$source}\n".json_encode($row,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);}}
$implicitTi=$parser->parse('体123','福彩3D',5.0)[0]??null;
$implicitBoth=$parser->parse('福体123','福彩3D',2.0)[0]??null;
$ambiguousLong=$parser->parse('1234','福彩3D',2.0)[0]??null;
$ambiguousMultiple=$parser->parse('123 456','福彩3D',2.0)[0]??null;
if(($implicitTi['category']??'')!=='体'||($implicitTi['amount']??'')!=='5.00'||($implicitBoth['category']??'')!=='福体'||($implicitBoth['amount']??'')!=='4.00'||($ambiguousLong['status']??'')!=='failed'||($ambiguousMultiple['status']??'')!=='failed'){
    fwrite(STDERR,"Failed implicit direct lottery/ambiguity guards\n".json_encode([$implicitTi,$implicitBoth,$ambiguousLong,$ambiguousMultiple],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);
}
$inherited=$parser->parse("123\n456\n789\n组六10",'福彩3D',2.0);
if(count($inherited)!==1||($inherited[0]['status']??'')!=='success'||($inherited[0]['count']??0)!==3||($inherited[0]['amount']??'')!=='30.00'||($inherited[0]['play_type']??'')!=='组六'){
    fwrite(STDERR,"Failed inherited batch DSL\n".json_encode($inherited,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);
}
$mixedDsl=$parser->parse("123组六20\n456组三30\n5胆678拖组六10\n百1十2个3单50\n和值15福体各100",'福彩3D',2.0);
if(count($mixedDsl)!==5||array_filter($mixedDsl,static fn(array $row):bool=>($row['status']??'')!=='success')||($mixedDsl[0]['play_type']??'')!=='组六'||($mixedDsl[1]['play_type']??'')!=='组三三码'||($mixedDsl[2]['play_type']??'')!=='1码拖3'||($mixedDsl[3]['ast']['play']??'')!=='ZX'||($mixedDsl[4]['play_type']??'')!=='和值15'||array_sum(array_map(static fn(array $row):float=>(float)$row['amount'],$mixedDsl))!==310.0){
    fwrite(STDERR,"Failed mixed shop DSL\n".json_encode($mixedDsl,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);
}
foreach(['包选3 20','TX 20','三同555 50','杀12 20','组三跨4 20','和值15组六20'] as $unsupported){
    $row=$parser->parse($unsupported,'福彩3D',2.0)[0]??null;
    if(!is_array($row)||($row['status']??'')!=='failed'||!str_contains((string)($row['reason']??''),'未配置')){fwrite(STDERR,"Failed unsupported DSL guard: {$unsupported}\n".json_encode($row,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");exit(1);}
}

$standaloneLotteryPrefixTicket = $parser->parse("福\n299-266-269-296五单一组\n2 胆300\n9胆200\n2459-2569组六40组三20\n共668", '福彩3D', 2.0);
if (count($standaloneLotteryPrefixTicket) !== 8
    || array_filter($standaloneLotteryPrefixTicket, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || abs(array_sum(array_map(static fn(array $row): float => (float)($row['amount'] ?? 0), $standaloneLotteryPrefixTicket)) - 668.0) > 0.001
    || ($standaloneLotteryPrefixTicket[0]['category'] ?? '') !== '福'
    || ($standaloneLotteryPrefixTicket[0]['count'] ?? 0) !== 4
    || ($standaloneLotteryPrefixTicket[1]['count'] ?? 0) !== 4) {
    fwrite(STDERR, "Failed: standalone lottery prefix multi-line ticket\n" . json_encode($standaloneLotteryPrefixTicket, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$fuTiDirectGroupTotal = $parser->parse('965 956 775 福体直组1米🈴12', '福彩3D', 2.0);
if (count($fuTiDirectGroupTotal) !== 3
    || array_filter($fuTiDirectGroupTotal, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || abs(array_sum(array_map(static fn(array $row): float => (float)($row['amount'] ?? 0), $fuTiDirectGroupTotal)) - 12.0) > 0.001
    || ($fuTiDirectGroupTotal[0]['count'] ?? 0) !== 6
    || ($fuTiDirectGroupTotal[0]['amount'] ?? '') !== '6.00') {
    fwrite(STDERR, "Failed: Fu/Ti direct-group total\n" . json_encode($fuTiDirectGroupTotal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

foreach (['965 956 775 福体直组1米共12元', '965 956 775 福体直组1米共计12元', '965 956 775 福体直组1米合12'] as $withTotal) {
    $rows = $parser->parse($withTotal, '福彩3D', 2.0);
    if (count($rows) !== 3
        || array_filter($rows, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
        || abs(array_sum(array_map(static fn(array $row): float => (float)($row['amount'] ?? 0), $rows)) - 12.0) > 0.001) {
        fwrite(STDERR, "Failed: single-word total marker {$withTotal}\n" . json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
        exit(1);
    }
}

foreach (['和', '共', '计', '合', '合计', '共计', '总计', '总合', '合共', '共合', '总共', '总和', '总额', '总数', '小计', '结', '结算', '🈴'] as $totalMarker) {
    $rows = $parser->parse('965 956 775 福体直组1米'.$totalMarker.'12元', '福彩3D', 2.0);
    if (count($rows) !== 3
        || array_filter($rows, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
        || abs(array_sum(array_map(static fn(array $row): float => (float)($row['amount'] ?? 0), $rows)) - 12.0) > 0.001) {
        fwrite(STDERR, "Failed: total marker {$totalMarker}\n" . json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
        exit(1);
    }
}
foreach (['和：12元', '共：12元', '计：12元', '合：12元', '结算：12元', '小计：12元'] as $punctuatedTotal) {
    $rows = $parser->parse('965 956 775 福体直组1米'.$punctuatedTotal, '福彩3D', 2.0);
    if (count($rows) !== 3 || array_filter($rows, static fn(array $row): bool => ($row['status'] ?? '') !== 'success') || abs(array_sum(array_map(static fn(array $row): float => (float)($row['amount'] ?? 0), $rows)) - 12.0) > 0.001) {
        fwrite(STDERR, "Failed: punctuated total marker {$punctuatedTotal}\n" . json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
        exit(1);
    }
}

$separateSelectionAndArithmeticTotal = $parser->parse("015689\n福组六组三各十快\n合计52+20=72", '福彩3D', 2.0);
if (count($separateSelectionAndArithmeticTotal) !== 2
    || array_filter($separateSelectionAndArithmeticTotal, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($separateSelectionAndArithmeticTotal, 'amount') !== ['10.00', '10.00']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $separateSelectionAndArithmeticTotal)) - 20.0) > 0.001
    || !str_contains((string)($separateSelectionAndArithmeticTotal[0]['raw_text'] ?? ''), '合计52+20=72')) {
    fwrite(STDERR, "Failed: separated selection with arithmetic grand total\n" . json_encode($separateSelectionAndArithmeticTotal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$completeTwoTicketArithmetic = $parser->parse("186-501-901-860-618-605-851-061-165-915-680-056-596-\n福三元单一元组共13*4=52\n\n015689\n福组六组三各十快\n合计52+20=72", '福彩3D', 2.0);
if (count($completeTwoTicketArithmetic) !== 4
    || array_filter($completeTwoTicketArithmetic, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($completeTwoTicketArithmetic, 'amount') !== ['39.00', '13.00', '10.00', '10.00']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $completeTwoTicketArithmetic)) - 72.0) > 0.001) {
    fwrite(STDERR, "Failed: complete two-ticket arithmetic sample\n" . json_encode($completeTwoTicketArithmetic, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$decimalStakeNumbers = implode(' ', array_map(static fn(int $number): string => str_pad((string)$number, 3, '0', STR_PAD_LEFT), range(0, 168)));
$decimalStake = $parser->parse($decimalStakeNumbers."\n福169注各单0.2米共计169*0.2=33.8米", '福彩3D', 2.0);
if (count($decimalStake) !== 1
    || ($decimalStake[0]['status'] ?? '') !== 'success'
    || ($decimalStake[0]['count'] ?? 0) !== 169
    || ($decimalStake[0]['stake_count'] ?? 0) !== 169
    || ($decimalStake[0]['amount'] ?? '') !== '33.80') {
    fwrite(STDERR, "Failed: decimal per-bet amount must not round\n" . json_encode($decimalStake, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$dotSeparatedArithmeticTickets = $parser->parse("794..974\n974..479\n直1倍\n组一米\n\n107直组1米\n8+9+2=19米福", '福彩3D', 2.0);
if (count($dotSeparatedArithmeticTickets) !== 4
    || array_filter($dotSeparatedArithmeticTickets, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($dotSeparatedArithmeticTickets, 'amount') !== ['8.00', '9.00', '1.00', '1.00']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $dotSeparatedArithmeticTickets)) - 19.0) > 0.001
    || !str_contains((string)($dotSeparatedArithmeticTickets[0]['raw_text'] ?? ''), '8+9+2=19米福')) {
    fwrite(STDERR, "Failed: dot-separated multi-ticket arithmetic sample\n" . json_encode($dotSeparatedArithmeticTickets, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$fuTiDirectGroupGrandTotal = $parser->parse("371   771  737  306  369一直一组，福体\n🈴40", '福彩3D', 2.0);
if (count($fuTiDirectGroupGrandTotal) !== 3
    || array_filter($fuTiDirectGroupGrandTotal, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $fuTiDirectGroupGrandTotal)) - 40.0) > 0.001) {
    fwrite(STDERR, "Failed: Fu/Ti direct-group grand total\n" . json_encode($fuTiDirectGroupGrandTotal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$countPrefixMultiLine = $parser->parse("福彩一单444   777\n福彩一单一组309   241   574   534   945   942   303\n🈴32", '福彩3D', 2.0);
if (count($countPrefixMultiLine) !== 4
    || array_filter($countPrefixMultiLine, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($countPrefixMultiLine, 'amount') !== ['4.00', '14.00', '12.00', '2.00']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $countPrefixMultiLine)) - 32.0) > 0.001) {
    fwrite(STDERR, "Failed: count-prefix multi-line total\n" . json_encode($countPrefixMultiLine, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$singleCountPrefix = $parser->parse("福彩两单一组507\n🈴6", '福彩3D', 2.0);
if (count($singleCountPrefix) !== 2
    || array_filter($singleCountPrefix, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($singleCountPrefix, 'amount') !== ['4.00', '2.00']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $singleCountPrefix)) - 6.0) > 0.001) {
    fwrite(STDERR, "Failed: single count-prefix ticket\n" . json_encode($singleCountPrefix, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$lineTerminatedCompound = $parser->parse("福彩两单一组079     407\n12\n福彩一单一组789  089   489\n403  307   947\n24\n福彩四码组六3倍0479\n30\n福彩五码组六三倍03479\n30\n🈴96", '福彩3D', 2.0);
if (count($lineTerminatedCompound) !== 6
    || array_filter($lineTerminatedCompound, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($lineTerminatedCompound, 'amount') !== ['8.00', '4.00', '12.00', '12.00', '30.00', '30.00']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $lineTerminatedCompound)) - 96.0) > 0.001) {
    fwrite(STDERR, "Failed: line-terminated compound tickets\n" . json_encode($lineTerminatedCompound, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$lineTerminatedGroupCount = $parser->parse("福彩两单一组079     407\n12\n福彩一单一组789  089   489\n403  307   947\n24\n福彩四码组六3倍0479\n30\n福彩五码组六三倍03479\n30\n🈴96", '福彩3D', 2.0);
if (count($lineTerminatedGroupCount) !== 6
    || array_filter($lineTerminatedGroupCount, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || array_column($lineTerminatedGroupCount, 'amount') !== ['8.00', '4.00', '12.00', '12.00', '30.00', '30.00']
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $lineTerminatedGroupCount)) - 96.0) > 0.001) {
    fwrite(STDERR, "Failed: line-terminated group-count sample\n" . json_encode($lineTerminatedGroupCount, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$fourTicketArithmetic = $parser->parse("福，825-472-584-275-458-943-972-932五单两组，80+32=112\n\n23479-24578两倍组三\n一倍组六  60\n\n体，580-375-087十单五组，90米\n03578一倍组三一倍组六\n🈴20+90+60+112=282", '福彩3D', 2.0);
if (count($fourTicketArithmetic) !== 10
    || array_filter($fourTicketArithmetic, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')
    || abs(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $fourTicketArithmetic)) - 282.0) > 0.001
    || array_column($fourTicketArithmetic, 'amount') !== ['80.00', '32.00', '20.00', '20.00', '10.00', '10.00', '60.00', '30.00', '10.00', '10.00']) {
    fwrite(STDERR, "Failed: four-ticket arithmetic grand total sample\n" . json_encode($fourTicketArithmetic, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

echo "QuickEntryParser tests passed\n";
