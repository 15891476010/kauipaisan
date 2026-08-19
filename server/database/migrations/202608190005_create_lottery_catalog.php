<?php
declare(strict_types=1);
use think\migration\Migrator;

final class CreateLotteryCatalog extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `lotteries` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,`name` VARCHAR(80) NOT NULL,`code` VARCHAR(80) NOT NULL,`status` TINYINT NOT NULL DEFAULT 1,`sort` INT NOT NULL DEFAULT 0,`created_at` DATETIME NULL,`updated_at` DATETIME NULL,`deleted_at` DATETIME NULL,UNIQUE KEY `uk_lottery_tenant_code` (`tenant_id`,`code`),INDEX `idx_lottery_status_sort` (`tenant_id`,`status`,`sort`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->execute("CREATE TABLE IF NOT EXISTS `site_lotteries` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,`site_id` BIGINT UNSIGNED NOT NULL,`lottery_id` BIGINT UNSIGNED NOT NULL,`created_at` DATETIME NOT NULL,UNIQUE KEY `uk_site_lottery` (`site_id`,`lottery_id`),INDEX `idx_site_lottery_site` (`site_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $now = date('Y-m-d H:i:s');
        foreach ([['福彩3D','fc3d',10],['排列三','pl3',20]] as [$name,$code,$sort]) {
            $exists = \think\facade\Db::name('lotteries')->where('tenant_id',1)->where('code',$code)->find();
            if (!$exists) \think\facade\Db::name('lotteries')->insert(['tenant_id'=>1,'name'=>$name,'code'=>$code,'sort'=>$sort,'status'=>1,'created_at'=>$now,'updated_at'=>$now]);
        }
        $sites = \think\facade\Db::name('sites')->whereNull('deleted_at')->column('id');
        $lotteries = \think\facade\Db::name('lotteries')->where('tenant_id',1)->where('status',1)->whereNull('deleted_at')->column('id');
        foreach ($sites as $siteId) foreach ($lotteries as $lotteryId) {
            if (!\think\facade\Db::name('site_lotteries')->where('site_id',$siteId)->where('lottery_id',$lotteryId)->find()) \think\facade\Db::name('site_lotteries')->insert(['tenant_id'=>1,'site_id'=>$siteId,'lottery_id'=>$lotteryId,'created_at'=>$now]);
        }
    }
    public function down(): void { $this->execute('DROP TABLE IF EXISTS `site_lotteries`'); $this->execute('DROP TABLE IF EXISTS `lotteries`'); }
}
