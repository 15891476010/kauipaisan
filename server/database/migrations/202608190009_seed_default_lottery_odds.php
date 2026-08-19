<?php
declare(strict_types=1);
use think\migration\Migrator;
use think\facade\Db;

final class SeedDefaultLotteryOdds extends Migrator
{
    public function up(): void
    {
        $rows=[
            ['直选','直选单注',0.1,1000,10000,50000,1000],
            ['组三多码','组三两码',0.1,150,2000,10000,150],
            ['组三多码','组三三码',0.1,50,3000,15000,50],
            ['组三多码','组三四码',0.1,25,6000,30000,25],
            ['组三多码','组三五码',0.1,15,8000,40000,15],
            ['组三多码','组三六码',0.1,10,16000,80000,10],
            ['组三多码','组三七码',0.1,7.142,10000,50000,7.142],
            ['组三多码','组三八码',0.1,5.3,20000,50000,5.3],
            ['组三多码','组三九码',1,4.166,10000,50000,4.166],
            ['组三多码','组三全包',1,3.3,40000,200000,3.3],
            ['组六多码','组六四码',0.1,37,5000,25000,37],
            ['组六多码','组六五码',0.1,15,10000,50000,15],
            ['组六多码','组六六码',0.1,7.5,20000,100000,7.5],
            ['组六多码','组六七码',1,4.285,10000,100000,4.285],
            ['组六多码','组六八码',1,2.678,20000,100000,2.678],
            ['组六多码','组六九码',1,1.785,20000,100000,1.785],
        ];
        $now=date('Y-m-d H:i:s'); $lotteries=Db::name('lotteries')->whereIn('code',['fc3d','pl3'])->whereNull('deleted_at')->select()->toArray();
        foreach($lotteries as $lottery) foreach($rows as $sort=>$item) if(!Db::name('lottery_odds')->where('lottery_id',$lottery['id'])->where('category',$item[0])->where('name',$item[1])->whereNull('deleted_at')->find()) Db::name('lottery_odds')->insert(['tenant_id'=>$lottery['tenant_id'],'lottery_id'=>$lottery['id'],'category'=>$item[0],'name'=>$item[1],'min_bet'=>$item[2],'odds_limit'=>$item[3],'single_bet_limit'=>$item[4],'single_item_limit'=>$item[5],'odds'=>$item[6],'offline_rebate'=>0,'status'=>1,'sort'=>100+$sort,'created_at'=>$now,'updated_at'=>$now]);
    }
    public function down(): void {}
}
