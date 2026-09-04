<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

/** Restore full-package labels that were persisted as the internal one-code atom. */
final class RestoreFullPackageLabels extends Migrator
{
    private const KEY='202609050003';

    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `bet_detail_package_backups` (
            `migration_key` VARCHAR(32) NOT NULL,
            `detail_id` BIGINT UNSIGNED NOT NULL,
            `original_detail_number_text` TEXT NOT NULL,
            `original_play_type` VARCHAR(80) NULL,
            `original_stop_number_text` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`migration_key`,`detail_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $rows=Db::name('bet_details')->alias('d')->join('bet_records r','r.id=d.bet_record_id')->leftJoin('user_stop_drops s','s.bet_detail_id=d.id')
            ->field('d.id,d.number_text,d.category,d.source_text,r.source_text AS record_source,r.formatted_text AS formatted_source,s.number_text AS stop_number_text,s.play_type')->select()->toArray();
        foreach($rows as $row){
            $source=(string)($row['record_source']??'').' '.(string)($row['formatted_source']??'').' '.(string)($row['source_text']??'');$label=null;
            if(preg_match('/(?:组三|组3)\s*(?:全包|包)/u',$source)===1)$label='组三全包';
            elseif(preg_match('/(?:组六|组6)\s*(?:全包|包)/u',$source)===1)$label='组六全包';
            elseif(preg_match('/豹子\s*(?:全包|包)/u',$source)===1)$label='豹子全包';
            elseif(preg_match('/对子\s*(?:全包|包)/u',$source)===1)$label='对子全包';
            if($label===null)continue;
            if((string)($row['number_text']??'')===$label&&(string)($row['play_type']??'')===$label)continue;
            $id=(int)($row['id']??0);if($id<1)continue;
            Db::transaction(function()use($id,$label):void{
                $current=Db::name('bet_details')->where('id',$id)->lock(true)->find();if(!is_array($current))return;
                $stop=Db::name('user_stop_drops')->where('bet_detail_id',$id)->find();
                $backup=Db::name('bet_detail_package_backups')->where('migration_key',self::KEY)->where('detail_id',$id)->find();
                if(!$backup)Db::name('bet_detail_package_backups')->insert(['migration_key'=>self::KEY,'detail_id'=>$id,'original_detail_number_text'=>(string)$current['number_text'],'original_play_type'=>(string)($stop['play_type']??''),'original_stop_number_text'=>(string)($stop['number_text']??''),'created_at'=>date('Y-m-d H:i:s')]);
                Db::name('bet_details')->where('id',$id)->update(['number_text'=>$label]);
                Db::name('user_stop_drops')->where('bet_detail_id',$id)->update(['number_text'=>$label,'play_type'=>$label]);
            });
        }
    }

    public function down(): void
    {
        foreach(Db::name('bet_detail_package_backups')->where('migration_key',self::KEY)->select()->toArray() as $row){$id=(int)$row['detail_id'];Db::name('bet_details')->where('id',$id)->update(['number_text'=>(string)$row['original_detail_number_text']]);Db::name('user_stop_drops')->where('bet_detail_id',$id)->update(['number_text'=>(string)($row['original_stop_number_text']??''),'play_type'=>(string)($row['original_play_type']??'')]);}
        $this->execute("DROP TABLE IF EXISTS `bet_detail_package_backups`");
    }
}
