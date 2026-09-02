<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use app\service\BetMultiplierAmount;

$tables = [
    [BetMultiplierAmount::GROUP_SIX_SINGLE_COMPOUND, [3=>12,4=>48,5=>120,6=>240,7=>420,8=>672]],
    [BetMultiplierAmount::GROUP_THREE_SINGLE_COMPOUND, [2=>12,3=>36,4=>72,5=>120,6=>180,7=>252,8=>336]],
    [BetMultiplierAmount::GROUP_SIX_DOUBLE_DRAG, [2=>4,3=>6,4=>8,5=>10,6=>12,7=>14,8=>16]],
    [BetMultiplierAmount::SINGLE_FULL_DRAG, [2=>38,3=>74,4=>122,5=>182,6=>254,7=>338,8=>434,9=>542]],
    [BetMultiplierAmount::STICKY_GROUP_SIX, [1=>72,2=>128,3=>170,4=>200,5=>220,6=>232,7=>238]],
    [BetMultiplierAmount::STICKY_GROUP_THREE, [1=>36,2=>68,3=>96,4=>120,5=>140,6=>156,7=>168]],
];
foreach ($tables as [$play, $values]) foreach ($values as $count => $expected) {
    if ($play->oneMultiplier($count) !== (float)$expected) {
        fwrite(STDERR, "Multiplier enum mismatch: {$play->value} {$count}\n");
        exit(1);
    }
}
$fixed = [
    [BetMultiplierAmount::LOTTERY_SINGLE,2],
    [BetMultiplierAmount::DIRECT,2],
    [BetMultiplierAmount::GROUP_SIX_THREE_CODE,10],
    [BetMultiplierAmount::GROUP_THREE_THREE_CODE,10],
    [BetMultiplierAmount::LEOPARD_SINGLE,2],
    [BetMultiplierAmount::COMPOUND,10],
    [BetMultiplierAmount::GROUP_OTHER,10],
    [BetMultiplierAmount::SPAN,10],
    [BetMultiplierAmount::SUM,10],
    [BetMultiplierAmount::SIZE_PARITY,10],
    [BetMultiplierAmount::PAIR_PACKAGE,10],
    [BetMultiplierAmount::LEOPARD_PACKAGE,10],
    [BetMultiplierAmount::GROUP_DRAG,10],
    [BetMultiplierAmount::DOUBLE_FLY,10],
];
foreach ($fixed as [$play, $expected]) if ($play->oneMultiplier() !== (float)$expected) {
    fwrite(STDERR, "Fixed multiplier enum mismatch: {$play->value}\n");
    exit(1);
}

echo "Bet multiplier amount enum tests passed\n";
