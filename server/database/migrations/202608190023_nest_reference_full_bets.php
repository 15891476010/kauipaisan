<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

final class NestReferenceFullBets extends Migrator
{
    public function up(): void
    {
        $now=date('Y-m-d H:i:s');
        foreach (Db::name('lotteries')->whereIn('code',['fc3d','pl3'])->whereNull('deleted_at')->field('id,tenant_id')->select()->toArray() as $lottery) {
            foreach ([['parent'=>'和值','name'=>'豹子全包','sort'=>14],['parent'=>'组六赖','name'=>'对子全包','sort'=>7]] as $target) {
                $parent=Db::name('lottery_odds_categories')->where('lottery_id',(int)$lottery['id'])->where('name',$target['parent'])->where('is_playable',0)->whereNull('deleted_at')->find();
                if (!$parent) continue;
                $direct=Db::name('lottery_odds_categories')->where('lottery_id',(int)$lottery['id'])->where('name',$target['name'])->where('is_playable',1)->whereNull('deleted_at')->find();
                if (!$direct) continue;
                $existing=Db::name('lottery_odds')->where('lottery_id',(int)$lottery['id'])->where('category_id',(int)$parent['id'])->where('name',$target['name'])->whereNull('deleted_at')->find();
                $data=['tenant_id'=>(int)$lottery['tenant_id'],'lottery_id'=>(int)$lottery['id'],'category_id'=>(int)$parent['id'],'category'=>(string)$parent['name'],'name'=>$target['name'],'min_bet'=>$direct['min_bet'],'odds_limit'=>$direct['odds_limit'],'single_bet_limit'=>$direct['single_bet_limit'],'single_item_limit'=>$direct['single_item_limit'],'odds'=>$direct['odds'],'offline_rebate'=>$direct['offline_rebate']??0,'status'=>1,'sort'=>$target['sort'],'updated_at'=>$now];
                if ($existing) Db::name('lottery_odds')->where('id',(int)$existing['id'])->update($data); else Db::name('lottery_odds')->insert(array_merge($data,['created_at'=>$now]));
                Db::name('lottery_odds_categories')->where('id',(int)$direct['id'])->update(['deleted_at'=>$now,'updated_at'=>$now]);
            }
        }
    }
    public function down(): void {}
}
