<?php
declare(strict_types=1);

namespace app\service;

use app\controller\UserBusiness;
use think\Request;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;

/**
 * Executes enabled automatic-bet robots.  The scheduler deliberately calls
 * UserBusiness::quickPlace() instead of duplicating the betting pipeline, so
 * odds, boards, cut-off rules, interception and ledgers stay identical to a
 * real member bet.
 */
final class RobotScheduler
{
    private string $workerToken;

    public function __construct()
    {
        $this->workerToken = bin2hex(random_bytes(16));
    }

    /** Run one scheduling pass. It is safe to call once per second. */
    public function tick(): array
    {
        $now = time();
        $rows = Db::name('robot_accounts')->where('status', 'running')
            ->whereNull('converted_at')->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', date('Y-m-d H:i:s', $now))
            ->order('next_run_at asc')->order('id asc')->limit(100)->select()->toArray();
        $result = ['due' => count($rows), 'claimed' => 0, 'success' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $robot = $this->claim((int)$row['id'], $now);
            if ($robot === null) continue;
            $result['claimed']++;
            try {
                $outcome = $this->execute($robot, $now);
                if ($outcome['status'] === 'success') $result['success']++;
                elseif ($outcome['status'] === 'skipped') $result['skipped']++;
                else $result['failed']++;
                $this->finish($robot, $outcome, time());
            } catch (\Throwable $error) {
                $result['failed']++;
                Log::error('robot scheduler failed robot='.$robot['id'].': '.$error->getMessage());
                $this->finish($robot, ['status' => 'failed', 'message' => $error->getMessage()], time());
            }
        }
        return $result;
    }

    /** Claim a due row in a short transaction, preventing duplicate workers. */
    private function claim(int $id, int $now): ?array
    {
        return Db::transaction(function () use ($id, $now): ?array {
            $robot = Db::name('robot_accounts')->where('id', $id)->lock(true)->find();
            if (!$robot || (string)$robot['status'] !== 'running' || $robot['converted_at'] !== null || empty($robot['next_run_at'])) return null;
            if (strtotime((string)$robot['next_run_at']) > $now) return null;
            // Reserve this slot immediately. The final schedule is written
            // after the bet, but another worker can no longer claim it.
            Db::name('robot_accounts')->where('id', $id)->update([
                'next_run_at' => date('Y-m-d H:i:s', $now + 3600),
                'updated_at' => date('Y-m-d H:i:s', $now),
            ]);
            $scheduled = strtotime((string)$robot['next_run_at']) ?: $now;
            $robot['_catchup'] = $scheduled < $now;
            $robot['_scheduled_at'] = $scheduled;
            return $robot;
        });
    }

    /** @return array{status:string,message?:string,lottery?:string,text?:string} */
    private function execute(array $robot, int $now): array
    {
        $start = strtotime((string)($robot['start_at'] ?? '')) ?: $now;
        // A historical start time is intentionally consumed one invocation at
        // a time. This advances catch-up work at one bet per second rather than
        // flooding the account with all missed periods in one pass.
        if ($now < $start) return ['status' => 'skipped', 'message' => '尚未到机器人开始时间'];

        // Apply configured daily pause windows to both real-time and
        // historical scheduling.
        $scheduleTime = !empty($robot['_catchup']) ? (int)($robot['_scheduled_at'] ?? $now) : $now;
        // Hourly weights are now the single daily schedule.  Do not fall
        // back to the legacy arbitrary skip-window list: an empty value is
        // treated as the default 21 slots (00:00-21:00), including the
        // mandatory 21:00-00:00 no-bet period.
        $hourState=$this->hourlyWeightState($robot,$scheduleTime);
        if(!$hourState['allowed']) return ['status'=>'skipped','message'=>'当前小时段权重为0或未抽中','skip_until'=>$hourState['retry_at']];

        $configs = json_decode((string)($robot['lottery_configs'] ?? '[]'), true);
        $ids = [];
        foreach ((array)$configs as $config) if (is_array($config) && (int)($config['enabled'] ?? 1) === 1 && (int)($config['lottery_id'] ?? 0) > 0) $ids[] = (int)$config['lottery_id'];
        if ($ids === []) return ['status' => 'failed', 'message' => '机器人未配置彩种'];
        // Prefer the configured lottery with the fewest recent robot bets;
        // this naturally rotates between lotteries before falling back to a
        // random choice for ties.
        $usage=[];foreach($ids as $candidateId){$name=(string)Db::name('lotteries')->where('id',$candidateId)->value('name');$usage[$candidateId]=(int)Db::name('user_stop_drops')->where('user_id',(int)$robot['user_id'])->where('lottery',$name)->where('created_at','>=',date('Y-m-d H:i:s',$now-86400))->count();}
        $lowest=min($usage);$choices=array_keys(array_filter($usage,static fn(int $count):bool=>$count===$lowest));$lotteryId=(int)$choices[random_int(0,count($choices)-1)];
        $lottery = Db::name('lotteries')->where('id', $lotteryId)->where('status', 1)->whereNull('deleted_at')->find();
        if (!$lottery) return ['status' => 'failed', 'message' => '关联彩种不存在或已停用'];

        $scheduledAt = !empty($robot['_catchup']) ? (int)($robot['_scheduled_at'] ?? $now) : null;
        if ($scheduledAt !== null && $this->inHistoricalClosedWindow((int)$lottery['id'], $scheduledAt)) {
            return ['status' => 'skipped', 'message' => '当日开奖后至零点为封盘对奖时段', 'lottery' => (string)$lottery['name']];
        }

        // System lotteries must be advanced before the bet is placed. Official
        // lotteries are advanced by the existing 60-second sync timer.
        if ((string)($lottery['source_type'] ?? 'official') === 'system') {
            try { (new SystemLotteryService())->runLottery($lottery); }
            catch (\Throwable $error) { return ['status' => 'failed', 'message' => '系统彩开奖推进失败：'.$error->getMessage()]; }
        }
        // The normal lottery timer performs this as well. Running the small,
        // idempotent pass here makes sure a robot never leaves its previous
        // batch pending when the timer and the robot happen to tick together.
        // During catch-up, settle against the simulated clock. In real time
        // the normal lottery sync does this, but historical replay must settle
        // the issue that ended at that day's cutoff before moving on.
        $this->settlePrevious($lottery, !empty($robot['_catchup']) ? (int)$robot['_scheduled_at'] : null);

        // During historical replay the next issue and its result are already
        // known.  Select a straight bet that either equals that draw (win)
        // or differs in one position (loss), according to the configured
        // win percentage.  Real-time bets keep the normal random generator:
        // the upcoming draw is unknowable until it is published.
        $target=$this->historicalTarget($lottery, !empty($robot['_catchup']) ? (int)$robot['_scheduled_at'] : null);
        $scheduleConfig=$this->monthlyConfig($robot, $scheduleTime);
        $monthlyCap=(float)($scheduleConfig['max_amount']??0);
        $monthlySpent=$this->monthlySpent((int)$robot['user_id'], $scheduleTime);
        if($monthlyCap>0 && $monthlySpent >= $monthlyCap-0.000001) return ['status'=>'skipped','message'=>'本月投注已达到设置上限','lottery'=>(string)$lottery['name']];
        $remaining=$monthlyCap>0 ? max(0,$monthlyCap-$monthlySpent) : null;
        $winWeight=(float)($scheduleConfig['win_weight']??($robot['win_weight']??50));
        $wantWin=$target!==null ? (random_int(1,10000) <= (int)round($winWeight*100) ) : null;
        $minAmount=(float)($robot['min_amount']??1);$maxAmount=(float)($robot['max_amount']??$minAmount);
        if($remaining!==null){$maxAmount=min($maxAmount,$remaining);$minAmount=min($minAmount,$maxAmount);}
        $texts = $this->generateTexts($robot, $lottery, count($ids) > 1, $target['draw']??null, $wantWin, $minAmount, $maxAmount);
        if ($texts === []) return ['status' => 'failed', 'message' => '未找到可匹配赔率的机器人玩法'];
        $session = [
            'scope' => 'user', 'tenant_id' => (int)$robot['tenant_id'], 'site_id' => (int)$robot['site_id'],
            'user_id' => (int)$robot['user_id'], 'username' => (string)$robot['username'], 'user_type' => 'site-user', 'robot_scheduler' => true,
        ];
        $token = $this->workerToken.'-'.$robot['id'];
        Cache::set('token:'.$token, $session, 300);
        // Avoid repeating one play format more than twice in succession.
        $lastKey=(string)($robot['last_play_key']??'');$repeat=(int)($robot['play_repeat_count']??0);
        if(count($texts)>1){
            $alternatives=array_values(array_filter($texts,fn(string $candidate):bool=>$this->playKey($candidate)!==$lastKey));
            // Keep alternatives at the front.  Shuffling the complete list
            // here made the repeat guard probabilistic and allowed a third
            // consecutive ticket with the same format.
            if($repeat>=2&&$alternatives!==[]) $texts=$alternatives;
            elseif($alternatives!==[]) $texts=array_merge($alternatives,array_values(array_diff($texts,$alternatives)));
        }
        $lastMessage = '下注失败';
        try {
            foreach ($texts as $text) {
                $post = ['confirmed' => true, 'text' => $text, 'lottery' => (string)$lottery['name'], 'board_code' => 'A'];
                if (!empty($robot['_catchup'])) $post['robot_backfill_at'] = date('Y-m-d H:i:s', (int)$robot['_scheduled_at']);
                if ($target!==null) $post['robot_target_issue']=(string)$target['issue'];
                $request = (new Request())->withHeader(['authorization' => 'Bearer '.$token])->withPost($post);
                $payload = json_decode((string)(new UserBusiness())->quickPlace($request)->getContent(), true);
                $code = (int)($payload['code'] ?? 500); $lastMessage = (string)($payload['message'] ?? $lastMessage);
                if ($code === 0 || $code === 409) {
                    $key=$this->playKey($text);$nextRepeat=$key===$lastKey?$repeat+1:1;
                    Db::name('robot_accounts')->where('id',(int)$robot['id'])->update(['last_play_key'=>$key,'play_repeat_count'=>$nextRepeat]);
                    return ['status' => 'success', 'lottery' => (string)$lottery['name'], 'text' => $text];
                }
                if (str_contains($lastMessage, '封盘') || str_contains($lastMessage, '禁止下注') || str_contains($lastMessage, '暂无可下注期号')) return ['status' => 'skipped', 'message' => $lastMessage, 'lottery' => (string)$lottery['name'], 'text' => $text];
            }
        } finally { Cache::delete('token:'.$token); }
        return ['status' => 'failed', 'message' => $lastMessage, 'lottery' => (string)$lottery['name']];
    }

    /** @return list<string> */
    private function generateTexts(array $robot, array $lottery, bool $allowFuTi = true, ?string $targetDraw = null, ?bool $wantWin = null, ?float $minOverride = null, ?float $maxOverride = null): array
    {
        $prefix = $this->weightedPrefix($robot, (string)$lottery['name'], $allowFuTi);
        $min = $minOverride!==null ? $minOverride : (float)($robot['min_amount'] ?? 1); $max = $maxOverride!==null ? $maxOverride : (float)($robot['max_amount'] ?? $min);
        $precision = max(0, min(2, (int)($robot['amount_precision'] ?? 0)));
        $rows = Db::name('lottery_odds')->where('lottery_id', (int)$lottery['id'])
            ->where('status', 1)->whereNull('deleted_at')->field('name,category')->order('sort asc')->select()->toArray();
        $candidates = [];
        foreach ($rows as $row) {
            $name = (string)($row['name'] ?? '');
            $category = (string)($row['category'] ?? '');
            // During historical replay, keep the configured win/loss ratio
            // while still allowing every supported play to participate.  The
            // old implementation returned a straight bet before inspecting
            // the odds catalog, which made every backfill ticket look like
            // “福 + three digits”.
            if ($targetDraw !== null && preg_match('/^\d{3}$/', $targetDraw) === 1 && $wantWin !== null
                && !$this->targetPlayCompatible($name, $category, $targetDraw, $wantWin)) {
                continue;
            }
            if (str_contains($name, '全包')) {
                // Package odds are multiplier-priced.  Pick the multiplier
                // from the configured batch total instead of always using
                // 1 倍 (which made package bets ignore the amount range).
                $multiplier = $this->randomPackageMultiplier($min, $max, $precision);
                if ($multiplier !== null) $candidates[] = $prefix.$name.$multiplier.'倍';
            } elseif (str_contains($name, '直选') || $name === '直' || str_contains($name, '单选')) {
                $digits = ($targetDraw !== null && $wantWin !== null && $wantWin)
                    ? $targetDraw : $this->digits(3);
                if ($targetDraw !== null && $wantWin === false) $digits = $this->differentDraw($targetDraw);
                $candidates[] = $prefix.$digits.'直各'.$this->randomUnitForTotal($min,$max,$precision,1).'元';
            } elseif (str_contains($name, '定位') || str_contains($category, '定位')) {
                $positions = ['百','十','个']; shuffle($positions); $count = (str_contains($name, '二码') || str_contains($category, '二码')) ? 2 : 1; $parts = []; $ways = 1;
                foreach (array_slice($positions, 0, $count) as $position) {
                    $positionIndex = ['百'=>0,'十'=>1,'个'=>2][$position];
                    $digits = ($targetDraw !== null && $wantWin !== null && $wantWin)
                        ? $targetDraw[$positionIndex]
                        : $this->digits(random_int(1, 3));
                    if ($targetDraw !== null && $wantWin === false && $digits === $targetDraw[$positionIndex]) $digits = (string)(((int)$digits + 1) % 10);
                    $ways*=strlen($digits); $parts[] = $position.$digits;
                }
                $candidates[] = $prefix.implode('', $parts).'各'.$this->randomUnitForTotal($min,$max,$precision,$ways).'元';
            } elseif (str_contains($name, '复式')) {
                $count = $this->numberWord((string)$name) ?: 3;
                $selected = $this->targetSelection($targetDraw, $wantWin, $count, 'compound');
                $ways=max(1,(int)round($count*($count-1)*($count-2)/6));
                $candidates[] = $prefix.'复式'.$selected.'各'.$this->randomUnitForTotal($min,$max,$precision,1).'元';
            } elseif (str_contains($name, '和值')) {
                $sum = ($targetDraw !== null && $wantWin !== null && $wantWin) ? array_sum(array_map('intval', str_split($targetDraw))) : random_int(0, 27);
                if ($targetDraw !== null && $wantWin === false && $sum === array_sum(array_map('intval', str_split($targetDraw)))) $sum = ($sum + 1) % 28;
                $candidates[] = $prefix.'和值'.$sum.'各'.$this->randomUnitForTotal($min,$max,$precision,1).'元';
            } elseif (str_contains($name, '跨度')) {
                $span = ($targetDraw !== null && $wantWin !== null && $wantWin) ? ((int)max(str_split($targetDraw)) - (int)min(str_split($targetDraw))) : random_int(0, 9);
                if ($targetDraw !== null && $wantWin === false && $span === ((int)max(str_split($targetDraw)) - (int)min(str_split($targetDraw)))) $span = ($span + 1) % 10;
                $candidates[] = $prefix.'跨度'.$span.'各'.$this->randomUnitForTotal($min,$max,$precision,1).'元';
            } elseif (str_contains($name, '胆拖') || str_contains($name, '拖') || str_contains($category, '胆拖')) {
                $count = (int)preg_replace('/\D/', '', $name); $count = max(2, min(9, $count ?: 2)); $family = str_contains($category, '组六') ? '组六' : '组三';
                $bankerCount = str_contains($category, '组六2') ? 2 : 1;
                $bankers = $this->targetBankers($targetDraw, $wantWin, $bankerCount, $family);
                $drag = $this->uniqueDigitsFrom($count, str_split($bankers));
                $candidates[] = $prefix.$family.$bankers.'拖'.$drag.'各'.$this->randomUnitForTotal($min,$max,$precision,1).'元';
            } elseif (str_contains($name, '组三') && !str_contains($name, '赖')) {
                $count = $this->numberWord($name) ?: 2; $ways=max(1,(int)round($count*($count-1)/2)*2); $selected=$this->targetSelection($targetDraw,$wantWin,$count,'z3'); $candidates[] = $prefix.'组三'.$this->countLabel($count).'码'.$selected.'各'.$this->randomUnitForTotal($min,$max,$precision,1).'元';
            } elseif (str_contains($name, '组六') && !str_contains($name, '赖')) {
                $count = $this->numberWord($name) ?: 3; $ways=max(1,(int)round($count*($count-1)*($count-2)/6)); $selected=$this->targetSelection($targetDraw,$wantWin,$count,'z6'); $label=$count>3?$this->countLabel($count).'码':''; $candidates[] = $prefix.'组六'.$label.$selected.'各'.$this->randomUnitForTotal($min,$max,$precision,1).'元';
            }
        }
        // Keep a small deterministic fallback for installations whose odds
        // names are custom; quickPlace still performs the authoritative match.
        $candidates[] = $prefix.$this->digits(3).'直各'.$this->randomUnitForTotal($min,$max,$precision,1).'元';
        $candidates = array_values(array_unique($candidates));
        shuffle($candidates);
        return array_values(array_unique($candidates));
    }

    private function drawType(string $draw): string
    {
        if ($draw[0] === $draw[1] && $draw[1] === $draw[2]) return 'bao';
        return count(array_unique(str_split($draw))) === 3 ? 'z6' : 'z3';
    }

    private function targetPlayCompatible(string $name, string $category, string $draw, bool $wantWin): bool
    {
        if ($wantWin === false) return true;
        $type = $this->drawType($draw);
        if (str_contains($name, '组三') || str_contains($category, '组三')) return $type === 'z3';
        if (str_contains($name, '组六') || str_contains($category, '组六')) return $type === 'z6';
        if (str_contains($name, '豹子')) return $type === 'bao';
        return true;
    }

    private function targetSelection(?string $draw, ?bool $wantWin, int $count, string $family): string
    {
        if ($draw === null || $wantWin === null) return $this->uniqueDigits($count);
        if (!$wantWin) {
            // Leave at least one drawn digit out (or add a digit not in the
            // draw) so compound/group selections cannot accidentally win.
            $drawDigits=array_values(array_unique(str_split($draw)));
            $outside=$this->uniqueDigitsFrom(1,$drawDigits);
            $selected=[$outside];
            $selected=array_merge($selected,str_split($this->uniqueDigitsFrom(max(0,$count-1),$selected)));
            return implode('',array_slice($selected,0,$count));
        }
        $digits = array_values(array_unique(str_split($draw)));
        if ($family === 'z3' && count($digits) >= 2) $digits = array_slice($digits, 0, 2);
        $need = max(0, $count - count($digits));
        if ($need > 0) $digits = array_merge($digits, str_split($this->uniqueDigitsFrom($need, $digits)));
        return implode('', array_slice($digits, 0, $count));
    }

    private function targetBankers(?string $draw, ?bool $wantWin, int $count, string $family): string
    {
        if ($draw !== null && $wantWin === false) {
            // A banker outside the draw is an unconditional losing胆拖 bet.
            return $this->uniqueDigitsFrom($count, str_split($draw));
        }
        if ($draw !== null && $wantWin === true) {
            $digits = array_values(array_unique(str_split($draw)));
            if ($family === '组三') $digits = [$draw[0]];
            if (count($digits) >= $count) return implode('', array_slice($digits, 0, $count));
        }
        return $this->uniqueDigits($count);
    }

    private function randomPackageMultiplier(float $min, float $max, int $precision): ?string
    {
        // All package plays are 10 元 per 1 倍.  Keep the generated total in
        // the configured batch range whenever the range can represent one.
        $lo = max(1, (int)ceil($min / 10));
        $hi = max($lo, (int)floor($max / 10));
        if ($hi < 1 || $max < 10) return null;
        return number_format((float)random_int($lo, $hi), 0, '.', '');
    }

    private function weightedPrefix(array $robot, string $lottery, bool $allowFuTi): string
    {
        // A single configured lottery cannot accept the other lottery's
        // prefix. 福体 is offered only when both lotteries are configured.
        if ($lottery === '福彩3D') return '福';
        if ($lottery === '排列三') return '体';
        $weights = ['福' => max(0, (float)($robot['weight_fu'] ?? 0)), '体' => max(0, (float)($robot['weight_ti'] ?? 0)), '福体' => $allowFuTi ? max(0, (float)($robot['weight_futi'] ?? 0)) : 0];
        $total = array_sum($weights); if ($total <= 0) return '福';
        $pick = random_int(1, (int)round($total * 10000)); $sum = 0;
        foreach ($weights as $name => $weight) { $sum += (int)round($weight * 10000); if ($pick <= $sum) return $name; }
        return '福';
    }

    private function randomAmount(float $min, float $max, int $precision): string
    {
        $scale = 10 ** $precision; $lo = (int)ceil($min * $scale); $hi = (int)floor($max * $scale); if ($hi < $lo) $hi = $lo;
        // Human-entered tickets commonly use amounts ending in 0 or 5.
        // Keep a smaller uniform branch so generated text does not become
        // completely repetitive while making those conventional amounts
        // noticeably more likely.
        if (random_int(1, 100) <= 70) {
            // Integer stakes favour values ending in 0/5; decimal stakes
            // favour 0.5 increments (0.5, 1.0, 1.5, ...).
            $step = $precision === 0 ? 5 : max(1, (int)round(0.5 * $scale));
            $first = (int)ceil($lo / $step) * $step;
            if ($first <= $hi) {
                $count = intdiv($hi - $first, $step); $value = $first + random_int(0, $count) * $step;
                return number_format($value / $scale, $precision, '.', '');
            }
        }
        return number_format(random_int($lo, $hi) / $scale, $precision, '.', '');
    }

    /** Generate a per-combination amount so the whole ticket stays in range. */
    private function randomUnitForTotal(float $min, float $max, int $precision, int $ways): string
    {
        $ways=max(1,$ways); $scale=10 ** $precision;
        $lo=(int)ceil(($min/$ways)*$scale); $hi=(int)floor(($max/$ways)*$scale);
        if($hi<$lo)$hi=$lo; $unit=(float)$this->randomAmount($lo/$scale,$hi/$scale,$precision);
        return number_format(max(1/$scale,$unit),$precision,'.','');
    }

    private function digits(int $length): string { $out = ''; for ($i=0; $i<$length; $i++) $out .= (string)random_int(0, 9); return $out; }
    private function uniqueDigits(int $length): string { return $this->uniqueDigitsFrom($length, []); }
    private function uniqueDigitsFrom(int $length, array $exclude): string { $pool=array_values(array_diff(range(0,9),array_map('intval',$exclude))); shuffle($pool); return implode('',array_slice($pool,0,max(1,min(count($pool),$length)))); }
    private function numberWord(string $name): int { foreach(['九'=>9,'八'=>8,'七'=>7,'六'=>6,'五'=>5,'四'=>4,'三'=>3,'二'=>2,'两'=>2,'一'=>1] as $word=>$value) if(str_contains($name,$word)) return $value; if(preg_match('/([2-9])码/u',$name,$m))return (int)$m[1]; return 0; }
    private function countLabel(int $count): string { return ['一'=>'一','二'=>'两','三'=>'三','四'=>'四','五'=>'五','六'=>'六','七'=>'七','八'=>'八','九'=>'九'][$count] ?? (string)$count; }

    private function skipWindowEnd(array $robot, int $timestamp): ?int
    {
        $windows=json_decode((string)($robot['skip_windows']??'[]'),true); if(!is_array($windows)||$windows===[])return null;
        $seconds=(int)date('H',$timestamp)*3600+(int)date('i',$timestamp)*60+(int)date('s',$timestamp);
        foreach($windows as $window){
            if(!is_array($window))continue; $start=(string)($window['start']??'');$end=(string)($window['end']??'');
            if(!preg_match('/^(\d{2}):(\d{2})$/',$start,$sm)||!preg_match('/^(\d{2}):(\d{2})$/',$end,$em))continue;
            $s=(int)$sm[1]*3600+(int)$sm[2]*60;$e=(int)$em[1]*3600+(int)$em[2]*60; if($s===$e)continue;
            $inside=$s<$e ? ($seconds>=$s&&$seconds<$e) : ($seconds>=$s||$seconds<$e); if(!$inside)continue;
            $day=strtotime(date('Y-m-d 00:00:00',$timestamp)); $endTs=$day+$e;
            if($s>$e&&$seconds<$e)$endTs-=86400; if($endTs<=$timestamp)$endTs+=86400; return $endTs;
        }
        return null;
    }

    private function settlePrevious(array $lottery, ?int $asOf = null): void
    {
        $query = Db::name('lottery_histories')->where('lottery_id', (int)$lottery['id'])->where('is_opened', 1);
        if ($asOf !== null) $query->where('open_time', '<=', date('Y-m-d H:i:s', $asOf));
        $histories = $query->order('open_time desc')->order('id desc')->limit($asOf === null ? 5 : 2)->select()->toArray();
        if ($histories === []) return;
        $settler = new BetSettlement();
        foreach ($histories as $history) {
            if (Db::name('bet_records')->where('issue_no', (string)$history['code'])->where('status', 'pending')->count() > 0) {
                try { $settler->settleForHistory($history, $lottery); }
                catch (\Throwable $error) { Log::warning('robot previous settlement failed issue='.$history['code'].': '.$error->getMessage()); }
            }
        }
    }

    /** Find the first already-opened issue after a historical bet timestamp. */
    private function historicalTarget(array $lottery, ?int $scheduledAt): ?array
    {
        if ($scheduledAt===null) {
            // System lotteries pre-draw the pending issue.  It is safe to use
            // that number in real time; official lotteries do not expose a
            // future result and therefore fall back to ordinary randomness.
            if ((string)($lottery['source_type']??'official')!=='system') return null;
            $row=Db::name('lottery_histories')->where('lottery_id',(int)$lottery['id'])
                ->where('is_opened',0)->whereNotNull('code')->order('open_time asc')->order('id asc')->find();
        } else {
            $row=Db::name('lottery_histories')->where('lottery_id',(int)$lottery['id'])
                ->where('is_opened',1)->where('open_time','>',date('Y-m-d H:i:s',$scheduledAt))
                ->whereNotNull('code')->order('open_time asc')->order('id asc')->find();
        }
        if (!is_array($row)) return null;
        $draw=(string)($row['one']??'').(string)($row['two']??'').(string)($row['three']??'');
        if (preg_match('/^\d{3}$/',$draw)!==1) $draw=preg_replace('/\D/','',(string)($row['numbers']??''))??'';
        return preg_match('/^\d{3}$/',$draw)===1 ? ['issue'=>(string)$row['code'],'draw'=>$draw] : null;
    }

    private function differentDraw(string $draw): string
    {
        $digits=str_split($draw); $digits[0]=(string)(((int)$digits[0]+1)%10); return implode('',$digits);
    }

    private function playKey(string $text): string
    {
        if(preg_match('/(豹子全包|对子全包|组三全包|组六全包|豹子|组六|组三|复式|二码定位|一码定位|定位|直选|单选|直|和值|跨度|单双|大小|飞|组)/u',$text,$m)) {
            $key=(string)$m[1];
            // 直/直选/单选 are presentation aliases of one format.  They
            // must share the same repeat counter so changing the wording
            // cannot bypass the two-consecutive-tickets limit.
            if(in_array($key,['直','直选','单选'],true)) return 'direct';
            if(str_contains($key,'定位')) return 'position';
            if(in_array($key,['组三全包','组六全包','对子全包'],true)) return $key;
            return $key;
        }
        return 'other';
    }

    /** Return whether this timestamp may place a bet and the next retry time. */
    private function hourlyWeightState(array $robot,int $timestamp): array
    {
        $rules=json_decode((string)($robot['hourly_weights']??'[]'),true);
        $hour=(int)date('H',$timestamp);
        if($hour>=21){$next=strtotime(date('Y-m-d 00:00:00',$timestamp))+86400;return ['allowed'=>false,'retry_at'=>$next];}
        // Robots created before hourly weights were introduced have NULL in
        // the column.  Keep them on the new 00:00-21:00 schedule instead of
        // silently allowing bets all night.
        if(!is_array($rules)||$rules===[])return ['allowed'=>true,'retry_at'=>null];
        $slot=$hour;$rule=$rules[$slot]??null;$weight=is_array($rule)?(float)($rule['weight']??0):0;
        $max=0.0;foreach($rules as $item)$max=max($max,(float)(is_array($item)?($item['weight']??0):0));
        if($weight<=0||$max<=0)return ['allowed'=>false,'retry_at'=>$timestamp+max(60,60-(int)date('s',$timestamp))];
        $allowed=random_int(1,10000)<=max(1,(int)round($weight/$max*10000));
        return ['allowed'=>$allowed,'retry_at'=>$timestamp+60];
    }

    /** Resolve the per-month override; unspecified months use robot defaults. */
    private function monthlyConfig(array $robot, int $timestamp): array
    {
        $month=date('Y-m',$timestamp);$rules=json_decode((string)($robot['monthly_rules']??'[]'),true);
        if(is_array($rules))foreach($rules as $rule)if(is_array($rule)&&($rule['month']??'')===$month)return $rule;
        return ['month'=>$month,'win_weight'=>(float)($robot['win_weight']??50),'max_amount'=>0];
    }

    private function monthlySpent(int $userId, int $timestamp): float
    {
        $from=date('Y-m-01 00:00:00',$timestamp);$to=date('Y-m-t 23:59:59',$timestamp);
        return (float)Db::name('bet_records')->where('user_id',$userId)->whereBetween('placed_at',[$from,$to])->sum('amount');
    }

    private function inHistoricalClosedWindow(int $lotteryId, int $timestamp): bool
    {
        // An opened issue earlier in the same day does not mean the whole
        // day is closed.  The previous implementation treated the first
        // result as a daily lock and consequently skipped every remaining
        // historical issue.  The daily cutoff is 21:00 here (the 21:00-00:00
        // block is already represented by hourlyWeightState); settlement of
        // the last batch happens before the next day resumes.
        return (int)date('H', $timestamp) >= 21;
    }

    private function finish(array $robot, array $outcome, int $now): void
    {
        // A robot must never keep retrying when the member has no available
        // balance/credit to fund the next bet.  Stop it immediately; the
        // operator can add score and start it again explicitly.
        $message=(string)($outcome['message']??'');
        if(($outcome['status']??'')==='failed' && preg_match('/余额不足|可用分数不足|会员可用分数不足|分数不足|信用余额不足|余额和信用余额/u',$message)){
            Db::name('robot_accounts')->where('id',(int)$robot['id'])->where('status','running')->update([
                'status'=>'stopped','next_run_at'=>null,'updated_at'=>date('Y-m-d H:i:s',$now),
            ]);
            return;
        }
        $min = max(1, (int)($robot['interval_min'] ?? 3)); $max = max($min, (int)($robot['interval_max'] ?? $min));
        $delayMinutes = random_int($min, $max);
        $delay = $delayMinutes * 60;
        $baseTime = !empty($robot['_catchup']) ? (int)($robot['_scheduled_at'] ?? $now) : $now;
        // On a cut-off/closed period, retry at the shortest configured
        // interval; successful and failed attempts use the random interval.
        if (($outcome['status'] ?? '') === 'skipped') $delay = $min * 60;
        if (!empty($outcome['skip_until'])) {
            $baseTime = (int)$outcome['skip_until'];
            $delay = 0;
        }
        $data = ['next_run_at' => date('Y-m-d H:i:s', $baseTime + $delay), 'updated_at' => date('Y-m-d H:i:s', $now)];
        if (($outcome['status'] ?? '') === 'success') $data['last_bet_at'] = date('Y-m-d H:i:s', $baseTime);
        Db::name('robot_accounts')->where('id', (int)$robot['id'])->where('status', 'running')->update($data);
    }
}
