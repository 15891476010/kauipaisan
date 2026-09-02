<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\service\QuickEntryCompiler;

$compiler = new QuickEntryCompiler();

$cases = [
    ['体89一单五组60', '排列三', ['直', '组六'], ['10.00', '50.00']],
    ['福418 403 901 406各四单一组计40', '福彩3D', ['直', '组六'], ['32.00', '8.00']],
    ['571 574 579 570四十倍直十倍组共400元福', '福彩3D', ['直', '组六'], ['320.00', '80.00']],
    ["福直2毛\n006 016 024", '福彩3D', ['直'], ['0.60']],
    ['和小50000米', '福彩3D', ['和小'], ['50000.00']],
    ['跨度7 100元', '福彩3D', ['跨度7'], ['100.00']],
    ['77双飞各1元', '福彩3D', ['双飞'], ['1.00']],
    ['组六1胆23拖各1000米', '福彩3D', ['1码拖2'], ['1000.00']],
    // 连字符在独胆语法中表示两个端点号码（不是连续范围）：1、3 各500=1000。
    ['独胆1-3各500', '福彩3D', ['独胆'], ['1000.00']],
    ['福独胆2-8各50合计100', '福彩3D', ['独胆'], ['100.00']],
    ['福880 942 869 908 248一组合10米', '福彩3D', ['组三', '组六'], ['2.00', '8.00']],
    ["福六码034689\n体六码245678\n组六30组三20\n🈴100", '福彩3D', ['组六六码', '组三六码', '组六六码', '组三六码'], ['30.00', '20.00', '30.00', '20.00']],
    ['福548、543、180一直组🈴️6', '福彩3D', ['组六'], ['6.00']],
    ['福276一倍值组4米', '福彩3D', ['直', '组六'], ['2.00', '2.00']],
    ['体1475829组6合计800', '排列三', ['组六七码'], ['800.00']],
    ['福体485复试各100合计200', '福彩3D', ['复式三码'], ['200.00']],
    ['123组三1倍', '福彩3D', ['组三三码'], ['10.00']],
    ['123组三三码1倍', '福彩3D', ['组三三码'], ['10.00']],
    ['三码组三123一倍', '福彩3D', ['组三三码'], ['10.00']],
    ['123组六1倍', '福彩3D', ['组六'], ['2.00']],
    ['123组六三码1倍', '福彩3D', ['组六三码'], ['10.00']],
    ['福2345678组六组三各1倍', '福彩3D', ['组六七码', '组三七码'], ['10.00', '10.00']],
    ['福体百位0134589十位0142659个位3704821各10合计6860', '福彩3D', ['三码定位'], ['6860.00']],
    ["福体二定:十1023689个0134678各50合计4900\n福体二定:百1023689个0134678各50合计4900\n福体二定:百1023689十1023689各50合计4900\n合计14700", '福彩3D', ['二码定位', '二码定位', '二码定位'], ['4900.00', '4900.00', '4900.00']],
    ['福358.357.378复试直选一倍', '福彩3D', ['直'], ['36.00']],
    ['福彩514.504.534.201.801.154.514.415.120直组各1元……18', '福彩3D', ['直', '组六'], ['9.00', '9.00']],
    ['02349福组六一倍合十', '福彩3D', ['组六五码'], ['10.00']],
    ['福组六8724561合计800', '福彩3D', ['组六七码'], ['800.00']],
    ["福体\n个位4\n个位3\n各300合计1200", '福彩3D', ['一码定位', '一码定位'], ['600.00', '600.00']],
    ["福123456组三各1000米\n福0123456组六各500米", '福彩3D', ['组三六码', '组六七码'], ['1000.00', '500.00']],
    ["福123456组三共1000米\n福0123456组六共500米", '福彩3D', ['组三六码', '组六七码'], ['1000.00', '500.00']],
    ["000 001 002 003 004 005 006 007 008 009 010 011 012 013 014 015 016 017 018 019 020 021 022 023 024 025 026 027 028 029 030 031 032 033 034 035 036 037 038 039 040 041 042 043 044 045 046 047 048 049 050 051 052 053 054 055 056 057 058 059 060 061 062 063 064 065 066 067 068 069 070 071 072 073 074 075 076 077 078 079 080 081 082 083 084 085 086 087 088 089 090 091 092 093 094 095 096 097 098 099 100 101 102 103 104 105 106 107 108 109 110 111 112 113 114 115 116 117 118 119 120 121 122 123 124 125 126 127 128 129 130 131 132 133 134 135 136 137 138 139 140 141 142 143 144 145 146 147 148 149 150 151 152 153 154 155 156 157 158 159 160 161 162 163 164 165 166 167 168 169 170 171 172 173 174 175 176 177 178 179 180 181 182 183 184 185 186 187 188 189 190 191 192 193 194 195 196 197 198 199 200 201 202 203 204 205 206 207 208 209 210 211 212 213 214 215 216 217 218 219 220 221 222 223 224 225 226 227 228 229 230 231 232 233 234 235 236 237 238 239 240 241 242 243 244 245 246 247 248 249 250 251 252 253 254 255 256 257 258 259 260 261 262 263 264 265 266 267 268 269 270 271 272 273 274 275 276 277 278 279 280 281 282 283 284 285 286 287 288 289 290 291 292 293 294 295 296 297 298 299 300 301 302 303 304 305 306 307 308 309 310 311 312 313 314 315 316 317 318 319 320 321 322 323 324 325 326 327\n🈴328米\n福直一米", '福彩3D', ['直'], ['328.00']],
    ['123 456 789共3注组直各0.2米3*0.4=1.2', '福彩3D', ['直', '组'], ['0.60', '0.60']],
    ['014 041 104 114 124 134 140 141 142 143 144 145 146 147 148 149 154 164 174 184 194 214 241 314 341 401 410 411 412 413 414 415 416 417 418 419 421 431 441 451 461 471 481 491 514 541 614 641 714 741 814 841 914 941直2角福54注合10.8', '福彩3D', ['直'], ['10.80']],
    ['福907.043.143.179.643.743.943.543.079.679.858.856.888一米直组合计26', '福彩3D', ['直', '组', '组', '组'], ['13.00', '1.00', '1.00', '11.00']],
    ["003 009 023 029 083 089 303 309 323 329 383 389 403 409 423 429 483 489 803 809 823 829 883 889 903 909 923 929 983 989\n447 457 467 474 475 476 477 547 574 647 674 744 745 746 747 754 764 774\n福彩单挑1米的\n合48米", '福彩3D', ['直'], ['48.00']],
    ['福123粘边赖组六10倍', '福彩3D', ['组六赖三码'], ['1700.00']],
    ['福4拖1236组六20', '福彩3D', ['1码拖4'], ['20.00']],
    ['1拖23456 2拖5678组三组六各10米', '福彩3D', ['1码拖5', '1码拖5', '1码拖4', '1码拖4'], ['10.00', '10.00', '10.00', '10.00']],
    ['福123复式三码100元', '福彩3D', ['复式三码'], ['100.00']],
    ['15862组六五码108元', '福彩3D', ['组六五码'], ['108.00']],
    ['123456 7890 1789组三各10米组六各20米复式各30米', '福彩3D', ['组三六码', '组六六码', '复式六码', '组三四码', '组六四码', '复式四码', '组三四码', '组六四码', '复式四码'], ['10.00', '20.00', '30.00', '10.00', '20.00', '30.00', '10.00', '20.00', '30.00']],
    ['47组六全托一倍共计16米', '福彩3D', ['2码拖8'], ['16.00']],
    ['组三7托012345689一倍', '福彩3D', ['1码拖9'], ['10.00']],
    ["福7\n全托组六100米组三30米\n合计130", '福彩3D', ['1码拖9', '1码拖9'], ['100.00', '30.00']],
];

$fuTi343 = implode(' ', array_map(static fn(int $number): string => str_pad((string)$number, 3, '0', STR_PAD_LEFT), range(0, 342))) . '一共343注福体直各10合计6860';
$fuTi343Result = $compiler->compile($fuTi343, '福彩3D', 2.0);
if (!$fuTi343Result->matchedInput()
    || count($fuTi343Result->rows) !== 1
    || ($fuTi343Result->rows[0]['status'] ?? '') !== 'success'
    || ($fuTi343Result->rows[0]['amount'] ?? '') !== '6860.00'
    || ($fuTi343Result->rows[0]['count'] ?? 0) !== 686) {
    fwrite(STDERR, "V2 Fu/Ti 343 direct declaration failed\n" . json_encode($fuTi343Result->rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$fuTi343Reverse = implode(' ', array_map(static fn(int $number): string => str_pad((string)$number, 3, '0', STR_PAD_LEFT), range(0, 342))) . '一共343注直福体各10合计6860';
$fuTi343ReverseResult = $compiler->compile($fuTi343Reverse, '福彩3D', 2.0);
if (!$fuTi343ReverseResult->matchedInput() || count($fuTi343ReverseResult->rows) !== 1 || ($fuTi343ReverseResult->rows[0]['amount'] ?? '') !== '6860.00') {
    fwrite(STDERR, "V2 Fu/Ti 343 reverse suffix order failed\n" . json_encode($fuTi343ReverseResult->rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$declaredDirect186Numbers = implode(' ', array_map(static fn(int $number): string => str_pad((string)$number, 3, '0', STR_PAD_LEFT), range(0, 185)));
$declaredDirect186 = $compiler->compile($declaredDirect186Numbers."\n\n福：186注直各5合计930", '福彩3D', 2.0);
if (!$declaredDirect186->matchedInput()
    || count($declaredDirect186->rows) !== 1
    || ($declaredDirect186->rows[0]['play_type'] ?? '') !== '直'
    || ($declaredDirect186->rows[0]['count'] ?? 0) !== 186
    || ($declaredDirect186->rows[0]['amount'] ?? '') !== '930.00') {
    fwrite(STDERR, "V2 trailing declared direct batch failed\n" . json_encode($declaredDirect186->rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$compoundSeven = $compiler->compile('福体0124689。复试各600合计1200', '福彩3D', 2.0);
if (!$compoundSeven->matchedInput()
    || count($compoundSeven->rows) !== 1
    || ($compoundSeven->rows[0]['play_type'] ?? '') !== '复式七码'
    || ($compoundSeven->rows[0]['category'] ?? '') !== '福体'
    || ($compoundSeven->rows[0]['count'] ?? 0) !== 2
    || ($compoundSeven->rows[0]['amount'] ?? '') !== '1200.00') {
    fwrite(STDERR, "V2 福体七码复式 total failed\n" . json_encode($compoundSeven->rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

foreach ($cases as [$source, $lottery, $plays, $amounts]) {
    $result = $compiler->compile($source, $lottery, 2.0);
    if (!$result->matchedInput() || array_column($result->rows, 'status') !== array_fill(0, count($result->rows), 'success')) {
        fwrite(STDERR, "V2 did not compile: {$source}\n" . json_encode($result->rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
        exit(1);
    }
    if (array_column($result->rows, 'play_type') !== $plays || array_column($result->rows, 'amount') !== $amounts) {
        fwrite(STDERR, "V2 result mismatch: {$source}\n" . json_encode($result->rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
        exit(1);
    }
}

$wrappedLian = $compiler->compile("福123粘边赖组六10倍\n组三5倍", '福彩3D', 2.0);
if (!$wrappedLian->matchedInput() || array_column($wrappedLian->rows, 'play_type') !== ['组六赖三码', '组三赖三码']) {
    fwrite(STDERR, "V2 wrapped lian syntax failed\n" . json_encode($wrappedLian->rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$continuous = $compiler->compile("123直各1元合计1\n456组六各2元合计2", '福彩3D', 2.0);
if (!$continuous->matchedInput() || count($continuous->rows) !== 2 || array_column($continuous->rows, 'amount') !== ['1.00', '2.00']) {
    fwrite(STDERR, "V2 continuous ticket split failed\n" . json_encode($continuous->rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$blankTickets = $compiler->compile("福123直各1元\n\n体456组六各2元", '福彩3D', 2.0);
if (!$blankTickets->matchedInput() || count($blankTickets->rows) !== 2 || array_column($blankTickets->rows, 'category') !== ['福', '体']) {
    fwrite(STDERR, "V2 blank ticket boundary failed\n" . json_encode($blankTickets->rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$complexTickets = $compiler->compile("福\n123 456\n直各1元合计2\n体\n789\n组六各2元合计2", '福彩3D', 2.0);
if (!$complexTickets->matchedInput() || count($complexTickets->rows) !== 2 || array_column($complexTickets->rows, 'category') !== ['福', '体']) {
    fwrite(STDERR, "V2 complex continuous tickets failed\n" . json_encode($complexTickets->rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$multilinePosition = $compiler->compile("福百012十34个567各1元", '福彩3D', 2.0);
if (!$multilinePosition->matchedInput() || ($multilinePosition->rows[0]['play_type'] ?? '') !== '三码定位' || ($multilinePosition->rows[0]['count'] ?? 0) !== 18) {
    fwrite(STDERR, "V2 position compound failed\n" . json_encode($multilinePosition->rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$multilineSimple = $compiler->compile("福\n123 456\n直各1元", '福彩3D', 2.0);
if (!$multilineSimple->matchedInput() || count($multilineSimple->rows) !== 1 || ($multilineSimple->rows[0]['amount'] ?? '') !== '2.00') {
    fwrite(STDERR, "V2 multiline simple ticket failed\n" . json_encode($multilineSimple->rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$ambiguous = $compiler->compile("111，222\n333，444", '福彩3D', 2.0);
if (!$ambiguous->matchedInput() || ($ambiguous->rows[0]['status'] ?? '') !== 'failed' || ($ambiguous->rows[0]['reason'] ?? '') !== '未识别到玩法') {
    fwrite(STDERR, "V2 ambiguity guard failed\n" . json_encode($ambiguous->rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

echo "QuickEntryCompiler V2 tests passed\n";
