<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/** Generates scheduled, pre-drawn issues for locally controlled lotteries. */
final class SystemLotteryService
{
    public function runAll(): array
    {
        $rows=Db::name('lotteries')->where('source_type','system')->where('status',1)->whereNull('deleted_at')->order('sort asc')->order('id asc')->select()->toArray();
        $result=[];
        foreach ($rows as $lottery) $result[]=$this->runLottery($lottery);
        return $result;
    }

    public function runLottery(array $lottery): array
    {
        $lotteryId=(int)($lottery['id']??0);
        if ($lotteryId<1) return ['lottery_id'=>0,'opened'=>0,'generated'=>0,'ok'=>false];
        $interval=max(5,min(86400,(int)($lottery['system_interval_seconds']??60)));
        $now=time(); $opened=0; $generated=0;
        $pending=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('is_opened',0)->order('open_time asc')->order('id asc')->find();
        if (!$pending) {
            $latest=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('is_opened',1)->order('open_time desc')->order('id desc')->find();
            if (!$latest) {
                $issue=(string)($lottery['system_issue_mode']??'auto')==='auto' ? $this->issueForTimestamp($now,$interval) : trim((string)($lottery['system_initial_issue']??''));
                if ($issue!=='') $pending=$this->insertPending($lottery,$issue,$now+$interval);
            } else {
                $nextTimestamp=strtotime((string)$latest['open_time'])+$interval;
                $nextIssue=(string)($lottery['system_issue_mode']??'auto')==='auto' ? $this->issueForTimestamp($nextTimestamp,$interval) : $this->nextIssue((string)$latest['code']);
                $pending=$this->insertPending($lottery,$nextIssue,$nextTimestamp);
            }
            if ($pending) $generated++;
        }
        // If the worker was offline for a long time, replaying thousands of
        // one-minute periods synchronously can make the API request time out.
        // System lotteries have no external draw history to preserve, so fast
        // forward an excessively stale pending issue to the next slot while
        // still settling it when a real bet exists.
        if ($pending) {
            $pendingTimestamp = strtotime((string)($pending['open_time'] ?? ''));
            $lagPeriods = $pendingTimestamp > 0 && $pendingTimestamp < $now
                ? intdiv($now - $pendingTimestamp, $interval) : 0;
            if ($lagPeriods > 100) {
                $draw = $this->digits((string)($pending['numbers'] ?? '')) ?: $this->randomDraw();
                $parts = str_split($draw);
                Db::name('lottery_histories')->where('id',(int)$pending['id'])->update([
                    'one'=>(int)$parts[0], 'two'=>(int)$parts[1], 'three'=>(int)$parts[2],
                    'numbers'=>implode(' ',$parts), 'draw_day'=>substr((string)$pending['open_time'],0,10),
                    'is_opened'=>1, 'updated_at'=>date('Y-m-d H:i:s'),
                ]);
                $openedHistory = Db::name('lottery_histories')->where('id',(int)$pending['id'])->find();
                if ($openedHistory && Db::name('bet_records')->where('issue_no',(string)$openedHistory['code'])->where('status','pending')->count()>0) {
                    (new BetSettlement())->settleForHistory($openedHistory,$lottery);
                }
                $nextIssue = (string)($lottery['system_issue_mode']??'auto')==='auto'
                    ? $this->issueForTimestamp($now + $interval, $interval)
                    : $this->nextIssue((string)$pending['code']);
                $pending = $this->insertPending($lottery, $nextIssue, $now + $interval);
                $opened++; $generated++;
            }
        }
        // Catch up every missed period in one invocation.  A system lottery is
        // also advanced from the user-facing /user/lotteries request, so a
        // small fixed limit (the old value was 10) leaves the pending issue in
        // the past for hours after a short service/cron outage.  That makes
        // the UI calculate a zero countdown and keep the betting mask up.
        // Keep a generous safety guard for corrupt data, while skipping the
        // relatively expensive settlement transaction when an issue has no
        // bets at all (the normal case for a catch-up run).
        for ($guard=0;$guard<10000 && $pending && (int)($pending['is_opened']??0)===0 && strtotime((string)$pending['open_time'])<=$now;$guard++) {
            $draw=$this->digits((string)($pending['numbers']??''));
            if ($draw==='') $draw=$this->randomDraw();
            $parts=str_split($draw);
            $openTime=(string)$pending['open_time'];
            Db::name('lottery_histories')->where('id',(int)$pending['id'])->update([
                'one'=>(int)$parts[0],'two'=>(int)$parts[1],'three'=>(int)$parts[2],
                'numbers'=>implode(' ',$parts),'draw_day'=>substr($openTime,0,10),'is_opened'=>1,'updated_at'=>date('Y-m-d H:i:s'),
            ]);
            $history=Db::name('lottery_histories')->where('id',(int)$pending['id'])->find();
            if ($history) {
                // Do not run the full settlement path for empty issues.  It
                // performs several locking queries and is unnecessary when no
                // user has a pending record for this issue.
                if (Db::name('bet_records')->where('issue_no',(string)$history['code'])->where('status','pending')->count()>0) {
                    (new BetSettlement())->settleForHistory($history,$lottery);
                }
                $opened++;
            }
            $nextTimestamp=strtotime($openTime)+$interval;
            $nextIssue=(string)($lottery['system_issue_mode']??'auto')==='auto' ? $this->issueForTimestamp($nextTimestamp,$interval) : $this->nextIssue((string)$pending['code']);
            $pending=$this->insertPending($lottery,$nextIssue,strtotime($openTime)+$interval);
            $generated++;
        }
        return ['lottery_id'=>$lotteryId,'opened'=>$opened,'generated'=>$generated,'ok'=>true];
    }

    /** @return array<string,mixed> */
    private function insertPending(array $lottery,string $issue,int $openTimestamp): array
    {
        $lotteryId=(int)$lottery['id']; $issue=trim($issue); if ($issue==='') throw new \InvalidArgumentException('系统彩缺少起始期号');
        $existing=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('code',$issue)->find();
        if ($existing && (int)($existing['is_opened']??0)===0) return $existing;
        // Changing a system lottery from a one-minute interval to a longer
        // interval can produce an issue code that already exists in the old
        // schedule (for example 2608290046).  Do not return that opened row as
        // the pending issue; advance to the next unused code instead.
        for ($guard=0; $existing && $guard<10000; $guard++) {
            $issue=$this->nextIssue($issue);
            $existing=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('code',$issue)->find();
            if ($existing && (int)($existing['is_opened']??0)===0) return $existing;
        }
        if ($existing) throw new \RuntimeException('系统彩无法生成唯一期号');
        $openTime=date('Y-m-d H:i:s',$openTimestamp); $draw=$this->randomDraw(); $parts=str_split($draw); $now=date('Y-m-d H:i:s');
        $id=Db::name('lottery_histories')->insertGetId([
            'tenant_id'=>(int)($lottery['tenant_id']??1),'lottery_id'=>$lotteryId,'code'=>$issue,'draw_day'=>substr($openTime,0,10),
            'one'=>(int)$parts[0],'two'=>(int)$parts[1],'three'=>(int)$parts[2],'numbers'=>implode(' ',$parts),
            'is_opened'=>0,'open_time'=>$openTime,'next_open_time'=>null,'next_code'=>null,'created_at'=>$now,'updated_at'=>$now,
        ]);
        return Db::name('lottery_histories')->where('id',$id)->find()?:[];
    }

    private function randomDraw(): string { return (string)random_int(0,9).(string)random_int(0,9).(string)random_int(0,9); }
    private function digits(string $value): string { $digits=preg_replace('/\D/','',$value)??''; return strlen($digits)>=3?substr($digits,0,3):''; }
    private function nextIssue(string $issue): string
    {
        if (preg_match('/^(.*?)(\d+)$/',$issue,$match)) return $match[1].str_pad((string)((int)$match[2]+1),strlen($match[2]),'0',STR_PAD_LEFT);
        return $issue.'1';
    }
    private function issueForTimestamp(int $timestamp,int $interval): string
    {
        $seconds=((int)date('H',$timestamp))*3600+((int)date('i',$timestamp))*60+(int)date('s',$timestamp);
        $slot=intdiv($seconds,max(1,$interval));
        return date('ymd',$timestamp).str_pad((string)$slot,4,'0',STR_PAD_LEFT);
    }
}
