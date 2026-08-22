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
    || ($digitSetGroups[1]['play_type'] ?? null) !== '组三五码'
    || ($digitSetGroups[1]['count'] ?? 0) !== 1
    || ($digitSetGroups[1]['amount'] ?? null) !== '600.00') {
    fwrite(STDERR, "Failed: digit-set group ticket\n" . json_encode($digitSetGroups, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$separateLotteryAndShortPlay = $parser->parse("福\n256，165，10单10组，\n\n体，117，20单", '福彩3D', 2.0);
if (count($separateLotteryAndShortPlay) !== 3
    || ($separateLotteryAndShortPlay[0]['status'] ?? null) !== 'success'
    || ($separateLotteryAndShortPlay[0]['category'] ?? null) !== '福'
    || ($separateLotteryAndShortPlay[0]['play_type'] ?? null) !== '直'
    || ($separateLotteryAndShortPlay[0]['count'] ?? 0) !== 2
    || ($separateLotteryAndShortPlay[0]['amount'] ?? null) !== '20.00'
    || ($separateLotteryAndShortPlay[1]['play_type'] ?? null) !== '组六'
    || ($separateLotteryAndShortPlay[1]['count'] ?? 0) !== 2
    || ($separateLotteryAndShortPlay[1]['amount'] ?? null) !== '20.00'
    || ($separateLotteryAndShortPlay[2]['category'] ?? null) !== '体'
    || ($separateLotteryAndShortPlay[2]['play_type'] ?? null) !== '直'
    || ($separateLotteryAndShortPlay[2]['count'] ?? 0) !== 1
    || ($separateLotteryAndShortPlay[2]['amount'] ?? null) !== '20.00') {
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
    || ($fuTiGroupEach[0]['amount'] ?? null) !== '216.00') {
    fwrite(STDERR, "Failed: Fu/Ti group-six each amount\n" . json_encode($fuTiGroupEach, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
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

echo "QuickEntryParser tests passed\n";
