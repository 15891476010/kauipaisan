<?php
declare(strict_types=1);

namespace app\service;

/**
 * One-multiplier principal for every supported three-digit lottery play.
 *
 * Money units (元/米/角/毛) are not handled here. This enum is the single
 * source of truth used only when the customer explicitly enters “N倍”.
 */
enum BetMultiplierAmount: string
{
    case LOTTERY_SINGLE = 'lottery_single';
    case DIRECT = 'direct';
    case GROUP_SIX_THREE_CODE = 'group_six_three_code';
    case GROUP_THREE_THREE_CODE = 'group_three_three_code';
    case LEOPARD_SINGLE = 'leopard_single';
    case COMPOUND = 'compound';
    case GROUP_OTHER = 'group_other';
    case SPAN = 'span';
    case GROUP_SIX_SINGLE_COMPOUND = 'group_six_single_compound';
    case GROUP_THREE_SINGLE_COMPOUND = 'group_three_single_compound';
    case SUM = 'sum';
    case SIZE_PARITY = 'size_parity';
    case PAIR_PACKAGE = 'pair_package';
    case LEOPARD_PACKAGE = 'leopard_package';
    case GROUP_DRAG = 'group_drag';
    case DOUBLE_FLY = 'double_fly';
    case GROUP_SIX_DOUBLE_DRAG = 'group_six_double_drag';
    case SINGLE_FULL_DRAG = 'single_full_drag';
    case STICKY_GROUP_SIX = 'sticky_group_six';
    case STICKY_GROUP_THREE = 'sticky_group_three';

    public function oneMultiplier(int $count = 0): float
    {
        return match ($this) {
            self::LOTTERY_SINGLE, self::DIRECT, self::GROUP_SIX_THREE_CODE,
            self::GROUP_THREE_THREE_CODE, self::LEOPARD_SINGLE => 2.0,
            self::COMPOUND, self::GROUP_OTHER, self::SPAN, self::SUM,
            self::SIZE_PARITY, self::PAIR_PACKAGE, self::GROUP_DRAG, self::DOUBLE_FLY => 10.0,
            self::LEOPARD_PACKAGE => 20.0,
            self::GROUP_SIX_SINGLE_COMPOUND => self::valueFor($count, [3=>12, 4=>48, 5=>120, 6=>240, 7=>420, 8=>672]),
            self::GROUP_THREE_SINGLE_COMPOUND => self::valueFor($count, [2=>12, 3=>36, 4=>72, 5=>120, 6=>180, 7=>252, 8=>336]),
            self::GROUP_SIX_DOUBLE_DRAG => self::valueFor($count, [2=>4, 3=>6, 4=>8, 5=>10, 6=>12, 7=>14, 8=>16]),
            self::SINGLE_FULL_DRAG => self::valueFor($count, [2=>38, 3=>74, 4=>122, 5=>182, 6=>254, 7=>338, 8=>434, 9=>542]),
            self::STICKY_GROUP_SIX => self::valueFor($count, [1=>72, 2=>128, 3=>170, 4=>200, 5=>220, 6=>232, 7=>238]),
            self::STICKY_GROUP_THREE => self::valueFor($count, [1=>36, 2=>68, 3=>96, 4=>120, 5=>140, 6=>158, 7=>168]),
        };
    }

    /** @param array<int, int|float> $table */
    private static function valueFor(int $count, array $table): float
    {
        if (!array_key_exists($count, $table)) {
            throw new \InvalidArgumentException('该玩法不支持'.$count.'码的一倍金额');
        }
        return (float)$table[$count];
    }
}
