<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

final class SeedAdditionalPl3Fc3dOdds extends Migrator
{
    public function up(): void
    {
        $lotteries=Db::name('lotteries')->whereIn('code',['fc3d','pl3'])->whereNull('deleted_at')->field('id,tenant_id')->select()->toArray();
        $now=date('Y-m-d H:i:s');
        $rows=[
            ['category'=>'组六多码','name'=>'组六六码','min_bet'=>'1.0','odds_limit'=>'1.2931','single_bet_limit'=>'40000','single_item_limit'=>'200000','odds'=>'1.2931','offline_rebate'=>'0','sort'=>120],
            ['category'=>'组六多码','name'=>'组六七码','min_bet'=>'1.0','odds_limit'=>'1.2605','single_bet_limit'=>'40000','single_item_limit'=>'200000','odds'=>'1.2605','offline_rebate'=>'0','sort'=>121],
            ['category'=>'组六多码','name'=>'对子全包','min_bet'=>'1.0','odds_limit'=>'3','single_bet_limit'=>'40000','single_item_limit'=>'200000','odds'=>'3','offline_rebate'=>'0','sort'=>122],
            ['category'=>'组六2胆拖','name'=>'2码拖2','min_bet'=>'0.1','odds_limit'=>'75','single_bet_limit'=>'2000','single_item_limit'=>'10000','odds'=>'75','offline_rebate'=>'0','sort'=>1],
            ['category'=>'组六2胆拖','name'=>'2码拖3','min_bet'=>'0.1','odds_limit'=>'50','single_bet_limit'=>'3000','single_item_limit'=>'15000','odds'=>'50','offline_rebate'=>'0','sort'=>2],
            ['category'=>'组六2胆拖','name'=>'2码拖4','min_bet'=>'0.1','odds_limit'=>'37.5','single_bet_limit'=>'4000','single_item_limit'=>'20000','odds'=>'37.5','offline_rebate'=>'0','sort'=>3],
            ['category'=>'组六2胆拖','name'=>'2码拖5','min_bet'=>'0.1','odds_limit'=>'30','single_bet_limit'=>'5000','single_item_limit'=>'25000','odds'=>'30','offline_rebate'=>'0','sort'=>4],
            ['category'=>'组六2胆拖','name'=>'2码拖6','min_bet'=>'0.1','odds_limit'=>'25','single_bet_limit'=>'6000','single_item_limit'=>'30000','odds'=>'25','offline_rebate'=>'0','sort'=>5],
            ['category'=>'组六2胆拖','name'=>'2码拖7','min_bet'=>'0.1','odds_limit'=>'21','single_bet_limit'=>'7000','single_item_limit'=>'35000','odds'=>'21','offline_rebate'=>'0','sort'=>6],
            ['category'=>'组六2胆拖','name'=>'2码拖8','min_bet'=>'0.1','odds_limit'=>'18','single_bet_limit'=>'8000','single_item_limit'=>'40000','odds'=>'18','offline_rebate'=>'0','sort'=>7],
            ['category'=>'单选全胆拖','name'=>'1码拖2','min_bet'=>'0.1','odds_limit'=>'47.368','single_bet_limit'=>'4000','single_item_limit'=>'20000','odds'=>'47.368','offline_rebate'=>'0','sort'=>1],
            ['category'=>'单选全胆拖','name'=>'1码拖3','min_bet'=>'0.1','odds_limit'=>'24.324','single_bet_limit'=>'7000','single_item_limit'=>'35000','odds'=>'24.324','offline_rebate'=>'0','sort'=>2],
            ['category'=>'单选全胆拖','name'=>'1码拖4','min_bet'=>'0.1','odds_limit'=>'14.754','single_bet_limit'=>'10000','single_item_limit'=>'50000','odds'=>'14.754','offline_rebate'=>'0','sort'=>3],
            ['category'=>'单选全胆拖','name'=>'1码拖5','min_bet'=>'0.1','odds_limit'=>'9.89','single_bet_limit'=>'10000','single_item_limit'=>'50000','odds'=>'9.89','offline_rebate'=>'0','sort'=>4],
            ['category'=>'单选全胆拖','name'=>'1码拖6','min_bet'=>'0.1','odds_limit'=>'7.086','single_bet_limit'=>'20000','single_item_limit'=>'100000','odds'=>'7.086','offline_rebate'=>'0','sort'=>5],
            ['category'=>'单选全胆拖','name'=>'1码拖7','min_bet'=>'0.1','odds_limit'=>'5.325','single_bet_limit'=>'20000','single_item_limit'=>'100000','odds'=>'5.325','offline_rebate'=>'0','sort'=>6],
            ['category'=>'单选全胆拖','name'=>'1码拖8','min_bet'=>'1.0','odds_limit'=>'4.147','single_bet_limit'=>'30000','single_item_limit'=>'150000','odds'=>'4.147','offline_rebate'=>'0','sort'=>7],
        ];
        foreach($lotteries as $lottery){
            foreach($rows as $row){
                $categoryId=(int)Db::name('lottery_odds_categories')->where('lottery_id',(int)$lottery['id'])->where('name',$row['category'])->whereNull('deleted_at')->value('id');
                if($categoryId<1) $categoryId=(int)Db::name('lottery_odds_categories')->insertGetId(['tenant_id'=>(int)$lottery['tenant_id'],'lottery_id'=>(int)$lottery['id'],'name'=>$row['category'],'is_playable'=>0,'status'=>1,'sort'=>200,'created_at'=>$now,'updated_at'=>$now]);
                $data=['category_id'=>$categoryId,'category'=>$row['category'],'min_bet'=>$row['min_bet'],'odds_limit'=>$row['odds_limit'],'single_bet_limit'=>$row['single_bet_limit'],'single_item_limit'=>$row['single_item_limit'],'odds'=>$row['odds'],'offline_rebate'=>$row['offline_rebate'],'sort'=>$row['sort'],'status'=>1,'updated_at'=>$now];
                $existing=Db::name('lottery_odds')->where('lottery_id',(int)$lottery['id'])->where('category_id',$categoryId)->where('name',$row['name'])->whereNull('deleted_at')->find();
                if($existing) Db::name('lottery_odds')->where('id',(int)$existing['id'])->update($data); else Db::name('lottery_odds')->insert(array_merge(['tenant_id'=>(int)$lottery['tenant_id'],'lottery_id'=>(int)$lottery['id'],'name'=>$row['name'],'created_at'=>$now],$data));
            }
        }
    }
    public function down(): void {}
}
