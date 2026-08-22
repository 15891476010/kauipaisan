<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

final class OrderReferenceDirectOdds extends Migrator
{
    public function up(): void
    {
        foreach (Db::name('lotteries')->whereIn('code',['fc3d','pl3'])->whereNull('deleted_at')->column('id') as $lotteryId) {
            Db::name('lottery_odds_categories')->where('lottery_id',(int)$lotteryId)->where('name','豹子全包')->whereNull('deleted_at')->update(['sort'=>64,'updated_at'=>date('Y-m-d H:i:s')]);
            Db::name('lottery_odds_categories')->where('lottery_id',(int)$lotteryId)->where('name','对子全包')->whereNull('deleted_at')->update(['sort'=>87,'updated_at'=>date('Y-m-d H:i:s')]);
        }
    }
    public function down(): void {}
}
