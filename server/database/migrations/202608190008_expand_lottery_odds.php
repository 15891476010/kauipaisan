<?php
declare(strict_types=1);
use think\migration\Migrator;
use think\facade\Db;

final class ExpandLotteryOdds extends Migrator
{
    public function up(): void
    {
        $this->execute("ALTER TABLE `lottery_odds` ADD COLUMN `min_bet` DECIMAL(12,4) NOT NULL DEFAULT 0.1 AFTER `name`, ADD COLUMN `odds_limit` DECIMAL(12,4) NOT NULL DEFAULT 0 AFTER `min_bet`, ADD COLUMN `single_bet_limit` DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER `odds_limit`, ADD COLUMN `single_item_limit` DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER `single_bet_limit`, ADD COLUMN `offline_rebate` DECIMAL(10,4) NOT NULL DEFAULT 0 AFTER `odds`");
        $templates=[
            ['一码定位','百位定位',0.1,9,10000,100000,9],['一码定位','十位定位',0.1,9,10000,100000,9],['一码定位','个位定位',0.1,9,10000,100000,9],
            ['二码定位','百十定位',0.1,90,2000,15000,90],['二码定位','百个定位',0.1,90,2000,15000,90],['二码定位','十个定位',0.1,90,2000,15000,90],
            ['三位玩法','三码定位',0.1,900,600,1500,900],['三位玩法','独胆',0.1,3.3,40000,200000,3.3],['三位玩法','双飞',0.1,16,10000,40000,16],['三位玩法','对子',0.1,30,10000,40000,30],
        ];
        $now=date('Y-m-d H:i:s'); $lotteries=Db::name('lotteries')->whereNull('deleted_at')->select()->toArray();
        foreach($lotteries as $lottery) foreach($templates as $sort=>$item) if(!Db::name('lottery_odds')->where('lottery_id',$lottery['id'])->where('category',$item[0])->where('name',$item[1])->find()) Db::name('lottery_odds')->insert(['tenant_id'=>$lottery['tenant_id'],'lottery_id'=>$lottery['id'],'category'=>$item[0],'name'=>$item[1],'min_bet'=>$item[2],'odds_limit'=>$item[3],'single_bet_limit'=>$item[4],'single_item_limit'=>$item[5],'odds'=>$item[6],'offline_rebate'=>0,'status'=>1,'sort'=>$sort+1,'created_at'=>$now,'updated_at'=>$now]);
    }
    public function down(): void { $this->execute("ALTER TABLE `lottery_odds` DROP COLUMN `offline_rebate`, DROP COLUMN `single_item_limit`, DROP COLUMN `single_bet_limit`, DROP COLUMN `odds_limit`, DROP COLUMN `min_bet`"); }
}
