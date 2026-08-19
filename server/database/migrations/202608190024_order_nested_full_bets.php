<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

final class OrderNestedFullBets extends Migrator
{
    public function up(): void
    {
        $now=date('Y-m-d H:i:s');
        foreach (Db::name('lotteries')->whereIn('code',['fc3d','pl3'])->whereNull('deleted_at')->column('id') as $lotteryId) {
            $parents=['和值'=>['豹子全包',78],'组六赖'=>['对子全包',94]];
            foreach ($parents as $parent=>$target) {
                $category=Db::name('lottery_odds_categories')->where('lottery_id',(int)$lotteryId)->where('name',$parent)->whereNull('deleted_at')->find();
                if ($category) Db::name('lottery_odds')->where('lottery_id',(int)$lotteryId)->where('category_id',(int)$category['id'])->where('name',$target[0])->whereNull('deleted_at')->update(['sort'=>$target[1],'updated_at'=>$now]);
            }
        }
    }
    public function down(): void {}
}
