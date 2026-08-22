<?php
declare(strict_types=1);
use think\migration\Migrator;
use think\facade\Db;

final class AddBetRecordsMenu extends Migrator
{
    public function up(): void
    {
        if (!Db::name('menus')->where('name','bet-records')->find()) {
            Db::name('menus')->insert(['parent_id'=>0,'title'=>'下单记录','name'=>'bet-records','path'=>'/bet-records','component'=>'ResourceView','icon'=>'List','sort'=>35,'status'=>1]);
        }
    }

    public function down(): void { Db::name('menus')->where('name','bet-records')->delete(); }
}
