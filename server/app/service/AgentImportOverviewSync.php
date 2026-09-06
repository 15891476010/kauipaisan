<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;

/** Imports only the reference site's total overview into local bet tables. */
final class AgentImportOverviewSync
{
    public static function run(int $batchId,array $batch,array $profile): array
    {
        $controller=new \app\controller\AgentImport();$loginMethod=new \ReflectionMethod($controller,'login');$loginMethod->setAccessible(true);$callMethod=new \ReflectionMethod($controller,'call');$callMethod->setAccessible(true);
        $login=$loginMethod->invoke($controller,$profile);$ak=$login['ak'];$from=(string)$batch['from_date'];$to=(string)$batch['to_date'];
        // The reference site's “投注明细” endpoint is the authoritative
        // source for raw text and original bet time.  Do not rebuild a bet
        // from gblr/gbl summary fields (bn is only an order key).
        return self::runBetTextDetails($batchId,$batch,$profile,$callMethod,$ak,$from,$to);
        /* legacy gblr materializer retained below for reference */
        $resolve=new \ReflectionMethod($controller,'resolveIssueRange');$resolve->setAccessible(true);$range=$resolve->invoke($controller,$profile['base_url'],$ak,$from,$to,4);
        $userMap=[];$records=Db::name('agent_import_records')->where('batch_id',$batchId)->where('entity_type','account')->whereIn('action',['created_member','reused'])->select()->toArray();
        foreach($records as $record){$ext=trim((string)($record['external_id']??''));$local=(int)($record['local_id']??0);if($ext==='')continue;$payload=json_decode((string)($record['payload']??''),true);$source=$payload['source']??[];if((int)($source['tp']??6)<6)continue;$user=Db::name('site_users')->where('id',$local)->where('site_id',(int)$batch['site_id'])->whereNull('deleted_at')->find();if($user)$userMap[$ext]=(int)$user['id'];}
        $stats=['pages'=>0,'overview_rows'=>0,'inserted_records'=>0,'inserted_details'=>0,'skipped_duplicate'=>0,'skipped_unknown_member'=>0,'failed_details'=>0];$page=1;$pages=1;
        self::log($batchId,'开始获取总货概览', ['from_issue'=>$range['from'],'to_issue'=>$range['to']]);
        do{$result=$callMethod->invoke($controller,$profile['base_url'],$ak,'ag.tz','gblr',['lt'=>4,'dnf'=>$range['from'],'dnt'=>$range['to'],'pn'=>$page,'ps'=>40]);$response=$result['response'];$data=$response['data']??[];$rows=(array)($data['rl']??$data['list']??[]);$stats['pages']=$page;$stats['overview_rows']+=count($rows);$tc=(int)($data['tc']??$data['total']??0);$pages=$tc>0?(int)ceil($tc/40):($rows!==[]?$page:0);self::log($batchId,'已获取总货概览第 '.$page.' 页',['rows'=>count($rows),'total'=>$tc]);
            foreach($rows as $source){if(!is_array($source))continue;$bli=trim((string)($source['bli']??''));$mi=trim((string)($source['mi']??''));$amount=(float)($source['am']??0);$count=(int)($source['bc']??0);if($bli===''||$amount<=0||$count<=0)continue;$userId=(int)($userMap[$mi]??0);if($userId<1){$stats['skipped_unknown_member']++;self::log($batchId,'跳过未匹配会员的主单',['bli'=>$bli,'mi'=>$mi],'warning');continue;}$fingerprint=hash('sha256','agent-import|'.(int)$batch['site_id'].'|'.$bli);if(Db::name('bet_records')->where('site_id',(int)$batch['site_id'])->where('submission_fingerprint',$fingerprint)->count()){ $stats['skipped_duplicate']++;continue; }
                // `bn` is the reference site's numeric order key, not the
                // original bet text.  The actual text is returned by gbl as
                // `nm` (for example Z6M0136/Z3M0136).
                $rawText=trim((string)($source['txt']??$source['ftxt']??''));
                $placed=self::dateTime((int)($source['at']??0));$details=[];$win=0.0;try{$detailResult=$callMethod->invoke($controller,$profile['base_url'],$ak,'ag.tz','gbl',['lt'=>4,'bli'=>$bli]);$detailData=$detailResult['response']['data']??[];$detailRows=(array)($detailData['bl']??[]);foreach($detailRows as $d){if(!is_array($d))continue;$dAmount=(float)($d['am']??0);$dWin=(float)($d['za']??0);$win+=$dWin;$detailRaw=trim((string)($d['nm']??$d['txt']??$d['ftxt']??''));if($detailRaw==='')$detailRaw=$bli;$details[]=['external_order_no'=>$bli,'number_text'=>$detailRaw,'category'=>$detailRaw,'amount'=>number_format($dAmount,2,'.',''),'odds'=>isset($d['rt'])?number_format((float)$d['rt'],3,'.',''):null,'win_amount'=>number_format($dWin,2,'.',''),'matched_count'=>(int)($d['dv']??0),'rebate'=>'0.00','status'=>((int)($d['st']??3)===0?'refunded':'pending'),'placed_at'=>self::dateTime((int)($d['bt']??$source['at']??0)),'source_text'=>$detailRaw];}}catch(\Throwable $e){$stats['failed_details']++;self::log($batchId,'主单详情获取失败',['bli'=>$bli,'error'=>$e->getMessage()],'error');}
                if($details!==[])$rawText=implode('、',array_map(static fn(array $d): string=>(string)$d['source_text'],$details));
                if($details===[])$details=[['external_order_no'=>$bli,'number_text'=>$rawText,'category'=>'','amount'=>number_format($amount,2,'.',''),'odds'=>null,'win_amount'=>'0.00','matched_count'=>$count,'rebate'=>'0.00','status'=>'pending','placed_at'=>$placed,'source_text'=>$rawText]];
                Db::startTrans();try{$recordId=(int)Db::name('bet_records')->insertGetId(['external_order_no'=>$bli,'submission_id'=>null,'tenant_id'=>(int)$batch['tenant_id'],'site_id'=>(int)$batch['site_id'],'user_id'=>$userId,'issue_no'=>(string)($source['dn']??''),'source_text'=>$rawText,'formatted_text'=>$rawText,'submission_fingerprint'=>$fingerprint,'bet_count'=>$count,'amount'=>number_format($amount,2,'.',''),'win_amount'=>number_format($win,2,'.',''),'status'=>'pending','sealed'=>0,'placed_at'=>$placed,'refunded_at'=>null,'created_at'=>$placed,'board_code'=>'A']);foreach($details as $detail){$detail['tenant_id']=(int)$batch['tenant_id'];$detail['site_id']=(int)$batch['site_id'];$detail['user_id']=$userId;$detail['bet_record_id']=$recordId;$detail['issue_no']=(string)($source['dn']??'');$detail['board_code']='A';Db::name('bet_details')->insert($detail);$stats['inserted_details']++;}$stats['inserted_records']++;Db::commit();}catch(\Throwable $e){Db::rollback();self::log($batchId,'本地主单写入失败',['bli'=>$bli,'error'=>$e->getMessage()],'error');}
            }
            $page++;
        }while($page<=$pages&&$page<=200);
        self::log($batchId,'总货概览同步完成',$stats);return $stats;
    }
    private static function runBetTextDetails(int $batchId,array $batch,array $profile,\ReflectionMethod $callMethod,string $ak,string $from,string $to): array
    {
        $controller=new \app\controller\AgentImport();$fromTs=strtotime($from.' 00:00:00');$toTs=strtotime($to.' 23:59:59');
        $thirdPartyConfig=ThirdPartyQuickEntryConfig::load((int)$batch['tenant_id'],(int)$batch['site_id']);
        if (!(bool)($thirdPartyConfig['enabled']??false)) throw new \RuntimeException('三方识别未启用，已停止导入，禁止回退本地解析');
        $thirdPartyClient=new ThirdPartyQuickEntryClient($thirdPartyConfig);
        $providerFormatter=new \app\controller\UserBusiness();
        $userMap=[];$records=Db::name('agent_import_records')->where('batch_id',$batchId)->where('entity_type','account')->whereIn('action',['created_member','reused'])->select()->toArray();
        foreach($records as $record){$ext=trim((string)($record['external_id']??''));$local=(int)($record['local_id']??0);if($ext==='')continue;$payload=json_decode((string)($record['payload']??''),true);$source=$payload['source']??[];if((int)($source['tp']??6)<6)continue;$user=Db::name('site_users')->where('id',$local)->where('site_id',(int)$batch['site_id'])->whereNull('deleted_at')->find();if($user)$userMap[$ext]=(int)$user['id'];}
        $stats=['pages'=>0,'overview_rows'=>0,'inserted_records'=>0,'inserted_details'=>0,'skipped_duplicate'=>0,'skipped_unknown_member'=>0,'failed_details'=>0];$page=1;$pages=1;
        do{$result=$callMethod->invoke($controller,$profile['base_url'],$ak,'ag.tz','gbtdl',['sp'=>0,'df'=>$fromTs,'dt'=>$toTs,'pn'=>$page,'ps'=>100]);$response=$result['response'];$data=$response['data']??[];$rows=(array)($data['dl']??[]);$stats['pages']=$page;$stats['overview_rows']+=count($rows);$tc=(int)($data['tc']??0);$pages=$tc>0?(int)ceil($tc/100):($rows!==[]?$page:0);self::log($batchId,'已获取投注明细第 '.$page.' 页',['rows'=>count($rows),'total'=>$tc]);
            foreach($rows as $source){if(!is_array($source))continue;$external=trim((string)($source['di']??''));$mi=trim((string)($source['mi']??''));if($external===''||$mi==='')continue;$userId=(int)($userMap[$mi]??0);if($userId<1){$stats['skipped_unknown_member']++;continue;}$fingerprint=hash('sha256','agent-import-detail|'.(int)$batch['site_id'].'|'.$external);if(Db::name('bet_records')->where('site_id',(int)$batch['site_id'])->where('submission_fingerprint',$fingerprint)->count()){$stats['skipped_duplicate']++;continue;}
                $raw=str_replace(['%NL','%MNS','%QT','%DQ'],["\n",'--',"'",'"'],trim((string)($source['bt']??$source['ot']??'')));$amount=(float)($source['amt']??0);$count=(int)($source['bc']??0);$status=(int)($source['iw']??0)===1?'refunded':'pending';$placed=self::dateTime((int)($source['at']??0));$isSports=(int)($source['f']??1)===0;$lottery=$isSports?'排列三':'福彩3D';$issue=(string)($isSports?($source['dnp']??''):($source['dnf']??''));
                if ($status !== 'refunded') {
                    try {
                        $recognized=[]; $lines=[];
                        for($attempt=0;$attempt<4;$attempt++) {
                            $recognized=$thirdPartyClient->recognize($raw,$isSports?3:4);
                            $preview=$providerFormatter->providerPreviewLines($recognized,$lottery);
                            $lines=array_values(array_filter($preview,static fn($line):bool=>is_array($line)&&($line['status']??'')==='success'));
                            if($lines!==[]) break;
                            if($attempt<3) usleep(1200000);
                        }
                        $lines=array_values(array_filter($lines,static fn($line):bool=>is_array($line)&&($line['status']??'')==='success'));
                        if ($lines===[]) throw new \RuntimeException('三方识别未返回有效明细');
                    } catch (\Throwable $e) {
                        $stats['failed_details']++; self::log($batchId,'三方识别失败，未写入注单',['di'=>$external,'error'=>$e->getMessage()],'error'); continue;
                    }
                } else $lines=[];
                $detailRows=[]; $recognizedAmount=0.0; $recognizedCount=0;
                foreach ($lines as $line) {
                    $parts=is_array($line['provider_place_parts']??null)&&$line['provider_place_parts']!==[]?$line['provider_place_parts']:[$line];
                    foreach ($parts as $part) {
                        $partAmount=(float)($part['amount']??0); $partCount=(int)($part['count']??$part['stake_count']??0);
                        if ($partAmount<=0||$partCount<=0) continue;
                        $detailSource=trim((string)($part['settlement_text']??$part['parse_text']??$part['raw_text']??$raw));
                        $odds=null; $lotteryId=(int)Db::name('lotteries')->where('tenant_id',(int)$batch['tenant_id'])->where('name',$lottery)->whereNull('deleted_at')->value('id');
                        if($lotteryId>0){$candidate=(new BetSettlement())->oddsFor($lotteryId,$detailSource,$partCount,'A'); if($candidate>0)$odds=number_format($candidate,4,'.','');}
                        $detailRows[]=['external_order_no'=>$external,'lottery_name'=>$lottery,'tenant_id'=>(int)$batch['tenant_id'],'site_id'=>(int)$batch['site_id'],'user_id'=>$userId,'issue_no'=>$issue,'number_text'=>(string)($part['number_text']??''),'category'=>(string)($part['category']??''),'_play_type'=>(string)($part['play_type']??''),'amount'=>number_format($partAmount,2,'.',''),'odds'=>$odds,'win_amount'=>'0.00','matched_count'=>0,'rebate'=>'0.00','status'=>'pending','placed_at'=>$placed,'source_text'=>$detailSource,'board_code'=>'A'];
                        $recognizedAmount+=$partAmount; $recognizedCount+=$partCount;
                    }
                }
                if($status!=='refunded'&&abs($recognizedAmount-$amount)>0.001){ $stats['failed_details']++; self::log($batchId,'识别结果与参考站金额不一致，未写入注单',['di'=>$external,'source_amount'=>$amount,'recognized_amount'=>$recognizedAmount,'source_count'=>$count,'recognized_count'=>$recognizedCount],'error'); continue; }
                Db::startTrans();try{$recordId=(int)Db::name('bet_records')->insertGetId(['external_order_no'=>$external,'lottery_name'=>$lottery,'submission_id'=>null,'tenant_id'=>(int)$batch['tenant_id'],'site_id'=>(int)$batch['site_id'],'user_id'=>$userId,'issue_no'=>$issue,'source_text'=>$raw,'formatted_text'=>$raw,'submission_fingerprint'=>$fingerprint,'bet_count'=>$count,'amount'=>number_format($amount,2,'.',''),'win_amount'=>'0.00','status'=>$status,'sealed'=>0,'placed_at'=>$placed,'refunded_at'=>$status==='refunded'?$placed:null,'created_at'=>$placed,'board_code'=>'A']);foreach($detailRows as $detail){$playType=(string)($detail['_play_type']??'');unset($detail['_play_type']);$detail['bet_record_id']=$recordId;$detailId=(int)Db::name('bet_details')->insertGetId($detail);Db::name('user_stop_drops')->insert(['tenant_id'=>(int)$batch['tenant_id'],'site_id'=>(int)$batch['site_id'],'user_id'=>$userId,'bet_detail_id'=>$detailId,'lottery'=>$lottery,'issue_no'=>$issue,'number_text'=>$detail['number_text'],'play_type'=>$playType,'stop_type'=>'none','original_amount'=>$detail['amount'],'actual_amount'=>$detail['amount'],'stop_amount'=>'0.00','original_odds'=>$detail['odds'],'actual_odds'=>$detail['odds'],'drop_odds'=>'0.0000','source_text'=>$detail['source_text'],'placed_at'=>$placed,'created_at'=>$placed]);$stats['inserted_details']++;}Db::name('agent_import_records')->insert(['batch_id'=>$batchId,'entity_type'=>'imported_bet','external_id'=>$external,'local_id'=>$recordId,'action'=>'created','payload'=>json_encode(['lottery'=>$lottery,'issue_no'=>$issue],JSON_UNESCAPED_UNICODE),'created_at'=>date('Y-m-d H:i:s')]);$stats['inserted_records']++;Db::commit();}catch(\Throwable $e){Db::rollback();$stats['failed_details']++;self::log($batchId,'投注明细写入失败',['di'=>$external,'error'=>$e->getMessage()],'error');}
            }$page++;
        }while($page<=$pages&&$page<=500);
        self::log($batchId,'投注明细同步完成',$stats);return $stats;
    }
    private static function dateTime(int $stamp): string {if($stamp>20000000000)$stamp=(int)floor($stamp/1000);return $stamp>0?date('Y-m-d H:i:s',$stamp):date('Y-m-d H:i:s');}
    private static function log(int $batchId,string $message,array $context=[],string $level='info'): void {Db::name('agent_import_records')->insert(['batch_id'=>$batchId,'entity_type'=>'sync_log','external_id'=>null,'local_id'=>null,'action'=>'progress','payload'=>json_encode(['level'=>$level,'message'=>$message,'context'=>$context],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>date('Y-m-d H:i:s')]);}
}
