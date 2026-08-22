<?php
declare(strict_types=1);
namespace app\service;

use think\facade\Db;

final class LotteryHistorySync
{
    private const DEFAULT_BASE_URL='https://api.huiniao.top/interface/home/lotteryHistory';

    private function baseUrl(): string
    {
        $value=Db::name('settings')->where('tenant_id',1)->whereNull('site_id')->where('key','lottery_api_base_url')->value('value');
        return trim((string)$value) ?: self::DEFAULT_BASE_URL;
    }

    private function apiType(array $lottery): string
    {
        return ['fc3d'=>'fcsd','pl3'=>'pls'][(string)$lottery['code']] ?? (string)$lottery['code'];
    }

    /** Create the provider's next issue as a real pending history row.
     *  The unique lottery/code key makes this safe to call from both cron and API reads.
     */
    public function ensureNextHistory(array $history, array $lottery): void
    {
        $nextCode=trim((string)($history['next_code']??''));
        if ($nextCode==='') return;
        $lotteryId=(int)($lottery['id']??$history['lottery_id']??0);
        if ($lotteryId<1 || Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('code',$nextCode)->count()>0) return;
        $nextOpen=$history['next_open_time']??null;
        $now=date('Y-m-d H:i:s');
        try {
            Db::name('lottery_histories')->insert([
                'tenant_id'=>(int)($lottery['tenant_id']??$history['tenant_id']??1),
                'lottery_id'=>$lotteryId,
                'code'=>$nextCode,
                'draw_day'=>$nextOpen ? substr((string)$nextOpen,0,10) : null,
                'one'=>null,'two'=>null,'three'=>null,'numbers'=>'',
                'open_time'=>$nextOpen,'next_open_time'=>null,'next_code'=>null,
                'created_at'=>$now,'updated_at'=>$now,
            ]);
        } catch (\Throwable $error) {
            // Another request may have inserted the same issue concurrently.
            if (Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('code',$nextCode)->count()===0) throw $error;
        }
    }

    public function syncLottery(array $lottery): array
    {
        $url=$this->baseUrl().'?'.http_build_query(['type'=>$this->apiType($lottery),'page'=>1,'limit'=>1]);
        $started=microtime(true);
        $context=stream_context_create(['http'=>['timeout'=>15,'ignore_errors'=>true,'header'=>"Accept: application/json\r\n"]]);
        $raw=@file_get_contents($url,false,$context);
        $elapsed=round((microtime(true)-$started)*1000,2);
        if ($raw===false) return ['lottery_id'=>(int)$lottery['id'],'inserted'=>0,'updated'=>0,'response_time_ms'=>$elapsed,'ok'=>false];
        $payload=json_decode($raw,true); $item=$payload['data']['data']['list'][0]??$payload['data']['last']??null;
        if (!is_array($item) || empty($item['code'])) return ['lottery_id'=>(int)$lottery['id'],'inserted'=>0,'updated'=>0,'response_time_ms'=>$elapsed,'ok'=>false];
        $lotteryId=(int)$lottery['id']; $code=(string)$item['code']; $now=date('Y-m-d H:i:s'); $one=$item['one']??null; $two=$item['two']??null; $three=$item['three']??null;
        $row=['tenant_id'=>(int)($lottery['tenant_id']??1),'lottery_id'=>$lotteryId,'code'=>$code,'draw_day'=>($item['day']??null) ?: null,'one'=>$one,'two'=>$two,'three'=>$three,'numbers'=>implode(' ',array_filter([$one,$two,$three],static fn($value)=>$value!==null && $value!=='')),'open_time'=>($item['open_time']??null) ?: null,'next_open_time'=>($item['next_open_time']??null) ?: null,'next_code'=>($item['next_code']??null) ?: null,'updated_at'=>$now];
        $existing=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('code',$code)->find();
        if ($existing) {
            // Keep the historical draw time unchanged once a row exists.
            $row['open_time']=$existing['open_time'];
            Db::name('lottery_histories')->where('id',$existing['id'])->update($row);
        } else {
            // New daily results use the actual database write time as draw time.
            $row['open_time']=$now;
            $row['created_at']=$now;
            Db::name('lottery_histories')->insert($row);
        }
        $history=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('code',$code)->find() ?: $row;
        $this->ensureNextHistory($history,$lottery);
        $settled=(new BetSettlement())->settleForHistory($history,$lottery);
        return ['lottery_id'=>$lotteryId,'inserted'=>$existing?0:1,'updated'=>$existing?1:0,'settled_records'=>$settled['records'],'won_records'=>$settled['won'],'response_time_ms'=>$elapsed,'ok'=>true];
    }

    public function backfill(int $lotteryId, int $pages=10, int $limit=1000, int $delaySeconds=0): array
    {
        $lottery=Db::name('lotteries')->where('id',$lotteryId)->whereNull('deleted_at')->find();
        if (!$lottery) throw new \InvalidArgumentException('彩票不存在');
        $pages=max(1,min(100,$pages)); $limit=max(1,min(1000,$limit)); $inserted=0; $updated=0; $received=0; $failedPages=[];
        for ($page=1; $page<=$pages; $page++) {
            $url=$this->baseUrl().'?'.http_build_query(['type'=>$this->apiType($lottery),'page'=>$page,'limit'=>$limit]);
            $context=stream_context_create(['http'=>['timeout'=>30,'ignore_errors'=>true,'header'=>"Accept: application/json\r\n"]]);
            $list=null;
            for ($attempt=1; $attempt<=3; $attempt++) {
                $raw=@file_get_contents($url,false,$context); $payload=$raw===false ? null : json_decode($raw,true); $list=$payload['data']['data']['list']??null;
                if (is_array($list)) break;
                usleep(500000*$attempt);
            }
            if (!is_array($list)) { $failedPages[]=$page; continue; }
            if ($list === []) break;
            $received+=count($list);
            foreach ($list as $item) {
                $code=(string)($item['code']??''); if ($code==='') continue; $now=date('Y-m-d H:i:s'); $one=$item['one']??null; $two=$item['two']??null; $three=$item['three']??null;
                $row=['tenant_id'=>(int)($lottery['tenant_id']??1),'lottery_id'=>$lotteryId,'code'=>$code,'draw_day'=>($item['day']??null) ?: null,'one'=>$one,'two'=>$two,'three'=>$three,'numbers'=>implode(' ',array_filter([$one,$two,$three],static fn($value)=>$value!==null && $value!=='')),'open_time'=>($item['open_time']??null) ?: null,'next_open_time'=>($item['next_open_time']??null) ?: null,'next_code'=>($item['next_code']??null) ?: null,'updated_at'=>$now];
                $existing=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('code',$code)->find();
                if ($existing) { Db::name('lottery_histories')->where('id',$existing['id'])->update($row); $updated++; }
                else { $row['created_at']=$now; Db::name('lottery_histories')->insert($row); $inserted++; }
                (new BetSettlement())->settleForHistory($row, $lottery);
            }
            if ($delaySeconds > 0 && $page < $pages) sleep($delaySeconds);
        }
        return ['lottery_id'=>$lotteryId,'pages'=>$pages,'limit'=>$limit,'received'=>$received,'inserted'=>$inserted,'updated'=>$updated,'failed_pages'=>$failedPages,'total'=>(int)Db::name('lottery_histories')->where('lottery_id',$lotteryId)->count()];
    }

    public function syncAll(): array
    {
        $lotteries=Db::name('lotteries')->where('status',1)->whereNull('deleted_at')->order('sort asc')->select()->toArray();
        $results=[]; foreach ($lotteries as $lottery) $results[]=$this->syncLottery($lottery); return $results;
    }
}
