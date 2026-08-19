<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

final class AddReferenceDirectOdds extends Migrator
{
    public function up(): void
    {
        $now=date('Y-m-d H:i:s');
        foreach(Db::name('lotteries')->whereIn('code',['fc3d','pl3'])->whereNull('deleted_at')->field('id,tenant_id')->select()->toArray() as $lottery){
            if(Db::name('lottery_odds_categories')->where('lottery_id',(int)$lottery['id'])->where('name','对子全包')->whereNull('deleted_at')->find()) continue;
            Db::name('lottery_odds_categories')->insert(['tenant_id'=>(int)$lottery['tenant_id'],'lottery_id'=>(int)$lottery['id'],'name'=>'对子全包','is_playable'=>1,'min_bet'=>'1.0','odds_limit'=>'3','single_bet_limit'=>'40000','single_item_limit'=>'200000','odds'=>'3','offline_rebate'=>0,'status'=>1,'sort'=>999,'created_at'=>$now,'updated_at'=>$now]);
        }
    }
    public function down(): void {}
}
