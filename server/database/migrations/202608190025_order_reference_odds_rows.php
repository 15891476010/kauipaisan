<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

final class OrderReferenceOddsRows extends Migrator
{
    public function up(): void
    {
        $sequence=[
            '一码定位'=>['口XX','X口X','XX口'],
            '二码定位'=>['口口X','口X口','X口口'],
            '@direct'=>['三码定位','独胆','双飞','对子','组六','组三'],
            '组三多码'=>['组三两码','组三三码','组三四码','组三五码','组三六码','组三七码','组三八码','组三九码','组三全包'],
            '组六多码'=>['组六四码','组六五码','组六六码','组六七码','组六八码','组六九码','组六全包'],
            '复式多码'=>['复式三码','复式四码','复式五码','复式六码','复式七码','复式八码','复式九码'],
            '组三胆拖'=>['1码拖2','1码拖3','1码拖4','1码拖5','1码拖6','1码拖7','1码拖8','1码拖9'],
            '组六胆拖'=>['1码拖2','1码拖3','1码拖4','1码拖5','1码拖6','1码拖7','1码拖8','1码拖9'],
            '跨度'=>['跨度0','跨度1','跨度2','跨度3','跨度4','跨度5','跨度6','跨度7','跨度8','跨度9'],
            '和值'=>['和值0-27','和值1-26','和值2-25','和值3-24','和值4-23','和值5-22','和值6-21','和值7-20','和值8-19','和值9-18','和值10-17','和值11-16','和值12-15','和值13-14','豹子全包'],
            '@direct-after-sum'=>['大小单双'],
            '组三赖'=>['组三赖一码','组三赖二码','组三赖三码','组三赖四码','组三赖五码','组三赖六码','组三赖七码'],
            '组六赖'=>['组六赖一码','组六赖二码','组六赖三码','组六赖四码','组六赖五码','组六赖六码','组六赖七码','对子全包'],
            '组六2胆拖'=>['2码拖2','2码拖3','2码拖4','2码拖5','2码拖6','2码拖7','2码拖8'],
            '单选全胆拖'=>['1码拖2','1码拖3','1码拖4','1码拖5','1码拖6','1码拖7','1码拖8','1码拖9'],
        ];
        $now=date('Y-m-d H:i:s');
        foreach(Db::name('lotteries')->whereIn('code',['fc3d','pl3'])->whereNull('deleted_at')->column('id') as $lotteryId){
            $sort=1;
            foreach($sequence as $category=>$names){
                if(str_starts_with($category,'@direct')){
                    foreach($names as $name) Db::name('lottery_odds_categories')->where('lottery_id',(int)$lotteryId)->where('name',$name)->where('is_playable',1)->whereNull('deleted_at')->update(['sort'=>$sort++,'updated_at'=>$now]);
                    continue;
                }
                $parent=Db::name('lottery_odds_categories')->where('lottery_id',(int)$lotteryId)->where('name',$category)->where('is_playable',0)->whereNull('deleted_at')->find();
                if(!$parent) continue;
                Db::name('lottery_odds_categories')->where('id',(int)$parent['id'])->update(['sort'=>$sort,'updated_at'=>$now]);
                foreach($names as $name) Db::name('lottery_odds')->where('lottery_id',(int)$lotteryId)->where('category_id',(int)$parent['id'])->where('name',$name)->whereNull('deleted_at')->update(['sort'=>$sort++,'updated_at'=>$now]);
            }
        }
    }
    public function down(): void {}
}
