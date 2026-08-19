<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\service\QuickEntryParser;

$parser = new QuickEntryParser();

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

$splitLotteryAndWholeAmount = $parser->parse("福\n百 01456789 十 01456789 个 01456789各 8 元\n\n豹子全包1500 元", '福彩3D');
if (count($splitLotteryAndWholeAmount) !== 2 || ($splitLotteryAndWholeAmount[0]['status'] ?? null) !== 'success' || ($splitLotteryAndWholeAmount[0]['count'] ?? 0) !== 512 || ($splitLotteryAndWholeAmount[0]['amount'] ?? null) !== '4096.00' || ($splitLotteryAndWholeAmount[1]['status'] ?? null) !== 'success' || ($splitLotteryAndWholeAmount[1]['amount'] ?? null) !== '1500.00') {
    fwrite(STDERR, "Failed: split lottery and whole amount\n" . json_encode($splitLotteryAndWholeAmount, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

echo "QuickEntryParser tests passed\n";
