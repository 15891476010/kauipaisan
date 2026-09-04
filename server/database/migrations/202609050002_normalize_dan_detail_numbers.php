<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

/** Store 独胆 selections as plain digits; the UI supplies the play label. */
final class NormalizeDanDetailNumbers extends Migrator
{
    private const KEY = '202609050002';

    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `bet_detail_dan_display_backups` (
            `migration_key` VARCHAR(32) NOT NULL,
            `detail_id` BIGINT UNSIGNED NOT NULL,
            `original_detail_number_text` TEXT NOT NULL,
            `original_stop_number_text` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`migration_key`, `detail_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $rows = Db::name('bet_details')->alias('d')->join('user_stop_drops s','s.bet_detail_id=d.id')
            ->where('s.play_type','独胆')->field('d.id,d.number_text,s.number_text AS stop_number_text')->select()->toArray();
        foreach ($rows as $row) {
            $detailId=(int)($row['id']??0); if($detailId<1)continue;
            $detail=$this->plain((string)($row['number_text']??'')); $stop=$this->plain((string)($row['stop_number_text']??''));
            if($detail===(string)($row['number_text']??'')&&$stop===(string)($row['stop_number_text']??''))continue;
            Db::transaction(function()use($detailId,$detail,$stop):void{
                $current=Db::name('bet_details')->where('id',$detailId)->lock(true)->find();if(!is_array($current))return;
                $backup=Db::name('bet_detail_dan_display_backups')->where('migration_key',self::KEY)->where('detail_id',$detailId)->find();
                if(!$backup)Db::name('bet_detail_dan_display_backups')->insert(['migration_key'=>self::KEY,'detail_id'=>$detailId,'original_detail_number_text'=>(string)$current['number_text'],'original_stop_number_text'=>(string)(Db::name('user_stop_drops')->where('bet_detail_id',$detailId)->value('number_text')??''),'created_at'=>date('Y-m-d H:i:s')]);
                Db::name('bet_details')->where('id',$detailId)->update(['number_text'=>$detail]);
                Db::name('user_stop_drops')->where('bet_detail_id',$detailId)->update(['number_text'=>$stop]);
            });
        }
    }

    private function plain(string $value): string
    {
        $tokens=preg_split('/[\s,，、]+/u',trim($value),-1,PREG_SPLIT_NO_EMPTY)?:[];
        return implode(' ',array_map(static fn(string $token):string=>preg_replace('/胆$/u','',trim($token))??trim($token),$tokens));
    }

    public function down(): void
    {
        foreach(Db::name('bet_detail_dan_display_backups')->where('migration_key',self::KEY)->select()->toArray() as $row){$id=(int)$row['detail_id'];Db::name('bet_details')->where('id',$id)->update(['number_text'=>(string)$row['original_detail_number_text']]);Db::name('user_stop_drops')->where('bet_detail_id',$id)->update(['number_text'=>(string)($row['original_stop_number_text']??'')]);}
        $this->execute("DROP TABLE IF EXISTS `bet_detail_dan_display_backups`");
    }
}
