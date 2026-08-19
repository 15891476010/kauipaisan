<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

/**
 * Clear the generated odds catalog before importing the reference site's data.
 * User-level overrides must be removed with their source odds rows.
 */
final class ClearLotteryOdds extends Migrator
{
    public function up(): void
    {
        Db::transaction(function (): void {
            Db::name('user_lottery_odds')->delete(true);
            Db::name('lottery_odds')->delete(true);
            Db::name('lottery_odds_categories')->delete(true);
        });
    }

    public function down(): void
    {
    }
}
