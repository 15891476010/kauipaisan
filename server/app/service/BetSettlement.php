<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class BetSettlement
{
    public function settleForHistory(array $history, array $lottery): array
    {
        if (array_key_exists('is_opened',$history) && (int)$history['is_opened']!==1) return ['records'=>0,'won'=>0];
        $lotteryId = (int)($lottery['id'] ?? 0);
        $issue = trim((string)($history['code'] ?? ''));
        if ($lotteryId < 1 || $issue === '') return ['records' => 0, 'won' => 0];
        $lotteryName = (string)($lottery['name'] ?? '');
        $draw = $this->digits($history);
        if ($draw === '') return ['records' => 0, 'won' => 0];

        $records = Db::name('bet_records')->where('issue_no', $issue)->where('status', 'pending')->select()->toArray();
        $processed = 0; $won = 0;
        foreach ($records as $record) {
            $settled=Db::transaction(function () use ($record, $lotteryId, $lotteryName, $draw): ?array {
                $lockedRecord=Db::name('bet_records')->where('id',(int)$record['id'])->lock(true)->find();
                if(!$lockedRecord || (string)$lockedRecord['status']!=='pending') return null;
                $details=Db::name('bet_details')->where('bet_record_id',(int)$lockedRecord['id'])->lock(true)->select()->toArray();
                if($details===[])return null;
                $detailIds=array_map('intval',array_column($details,'id'));
                $stopRows=Db::name('user_stop_drops')->whereIn('bet_detail_id',$detailIds)->lock(true)->select()->toArray();
                $stops=[];foreach($stopRows as $stop)$stops[(int)$stop['bet_detail_id']]=$stop;
                $totalWin=0.0;$totalRebate=0.0;$totalOffline=0.0;$matchedLottery=false;$waterItems=[];$totalMatched=0;$totalSelections=0;
                foreach($details as $detail){
                    $stop=$stops[(int)$detail['id']]??null;
                    if(!$stop||(string)$stop['lottery']!==$lotteryName)continue;
                    $matchedLottery=true;
                    // New detail rows keep one compact expression (for example
                    // “三123456”, “66飞”, “和小” or “874直”) instead of
                    // expanding every possible three-digit combination. Keep
                    // those expressions as match tokens; legacy expanded rows
                    // containing whitespace-separated three-digit numbers keep
                    // working unchanged.
                    $numbers=preg_split('/\s+/',trim((string)$detail['number_text']))?:[];
                    $numbers=array_values(array_filter($numbers,static fn(string $number):bool=>trim($number)!==''));
                    if($numbers===[]) {
                        $fallback=trim((string)($detail['source_text']??''));
                        if($fallback!=='') $numbers=[$fallback];
                    }
                    if($numbers===[])throw new \RuntimeException('注单明细 #'.(int)$detail['id'].' 没有可结算的玩法表达式，已停止整单结算');
                    [$odds,$legacyFallback]=$this->lockedOdds($detail,$stop,$lotteryId,count($numbers));
                    $payout=$this->detailPayout($numbers,$draw,(string)($detail['source_text']??''),(float)$detail['amount'],$odds);
                    $win=$payout['win'];$totalWin+=$win;$totalRebate+=(float)($detail['rebate']??0);
                    $totalMatched+=(int)$payout['matched'];$totalSelections+=count($numbers);
                    $totalOffline+=WaterLedger::calculate((float)$detail['amount'],(float)($stop['drop_odds']??0))['amount'];$waterItems[]=['detail'=>$detail,'stop'=>$stop];
                    // Persist the actual winning-combination count so the SaaS
                    // can distinguish a full multi-number hit from a partial hit.
                    $detailUpdate=['win_amount'=>number_format($win,2,'.',''),'status'=>$win>0?'won':'unwon','matched_count'=>(int)$payout['matched']];
                    if($legacyFallback)$detailUpdate['odds']=number_format($odds,4,'.','');
                    Db::name('bet_details')->where('id',(int)$detail['id'])->update($detailUpdate);
                    if($legacyFallback){
                        Db::name('user_stop_drops')->where('bet_detail_id',(int)$detail['id'])->update(['actual_odds'=>number_format($odds,4,'.','')]);
                        AuditLogger::write(['tenant_id'=>(int)$lockedRecord['tenant_id'],'user_id'=>(int)$lockedRecord['user_id']],'bet_settlement_legacy_odds','bet_detail:'.(int)$detail['id'],['bet_record_id'=>(int)$lockedRecord['id'],'lottery_id'=>$lotteryId,'fallback_odds'=>$odds]);
                    }
                }
                if(!$matchedLottery)return null;
                $status=$totalWin>0?'won':'unwon';
                Db::name('bet_records')->where('id', (int)$record['id'])->update([
                    'win_amount' => number_format($totalWin, 2, '.', ''),
                    'status' => $status,
                ]);
                if (!empty($lockedRecord['submission_id'])) $this->syncSubmissionSummary((int)$lockedRecord['submission_id']);
                $userId = (int)$lockedRecord['user_id']; $siteId = (int)$lockedRecord['site_id'];
                $amount = (float)$lockedRecord['amount'];
                $user = Db::name('site_users')->where('id', $userId)->where('site_id', $siteId)->lock(true)->find();
                if (!$user) throw new \RuntimeException('结算用户不存在');
                $before = (float)$user['balance'];
                $availableBefore = $before + (float)$user['credit_balance'] - (float)$user['used_balance'];
                $balanceChange = $totalWin - $amount;
                $balanceExpression = 'balance '.($balanceChange >= 0 ? '+ ' : '- ').number_format(abs($balanceChange), 2, '.', '');
                Db::name('site_users')->where('id', $userId)->where('site_id', $siteId)->update([
                    'balance' => Db::raw($balanceExpression),
                    'used_balance' => Db::raw('GREATEST(used_balance - '.number_format($amount, 2, '.', '').', 0)'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                CreditLedger::userSettlement(array_merge($lockedRecord, ['organization_id'=>$user['organization_id'] ?? null]), $totalWin, $availableBefore, $availableBefore + $totalWin);
                foreach ($waterItems as $waterItem) WaterLedger::recordForDetail($lockedRecord, $user, $waterItem['detail'], $waterItem['stop']);
                $houseProfit = $amount - $totalWin - $totalRebate;
                $this->allocateOrganizationProfit($lockedRecord, $user, $houseProfit);
                $billDate = substr((string)$lockedRecord['placed_at'], 0, 10);
                $bill = Db::name('bills')->where('site_id', $siteId)->where('user_id', $userId)->where('bill_date', $billDate)->find();
                if ($bill) Db::name('bills')->where('id', (int)$bill['id'])->update([
                    'bet_count' => (int)$bill['bet_count'] + (int)$lockedRecord['bet_count'],
                    'amount' => number_format((float)$bill['amount'] + $amount, 2, '.', ''),
                    'win_amount' => number_format((float)$bill['win_amount'] + $totalWin, 2, '.', ''),
                    'offline_rebate' => number_format((float)$bill['offline_rebate'] + $totalOffline, 2, '.', ''),
                    'profit' => number_format((float)$bill['profit'] + $totalWin - $amount + $totalOffline, 2, '.', ''),
                ]);
                else Db::name('bills')->insert([
                    'tenant_id' => (int)$lockedRecord['tenant_id'], 'site_id' => $siteId, 'user_id' => $userId,
                    'bill_date' => $billDate, 'bet_count' => (int)$lockedRecord['bet_count'], 'amount' => number_format($amount, 2, '.', ''),
                    'rebate' => '0.00', 'offline_rebate' => number_format($totalOffline, 2, '.', ''), 'win_amount' => number_format($totalWin, 2, '.', ''),
                    'profit' => number_format($totalWin - $amount + $totalOffline, 2, '.', ''), 'created_at' => date('Y-m-d H:i:s'),
                ]);
                return ['win'=>$totalWin];
            });
            if($settled!==null){$processed++;if((float)$settled['win']>0)$won++;}
        }
        return ['records' => $processed, 'won' => $won];
    }

    private function syncSubmissionSummary(int $submissionId): void
    {
        if ($submissionId < 1) return;
        $records=Db::name('bet_records')->where('submission_id',$submissionId)->select()->toArray();
        if ($records===[]) return;
        $amount=0.0;$betCount=0;$winAmount=0.0;$sealed=0;$status='pending';$refundedAt=null;
        foreach ($records as $record) {
            $amount+=(float)($record['amount']??0);
            $betCount+=(int)($record['bet_count']??0);
            $winAmount+=(float)($record['win_amount']??0);
            $sealed=max($sealed,(int)($record['sealed']??0));
            $rowStatus=(string)($record['status']??'pending');
            if ($rowStatus==='refunded') {
                $status='refunded';
                $refundedAt = $refundedAt ?: ($record['refunded_at'] ?? null);
            } elseif ($status==='pending' && $rowStatus==='won') {
                $status='won';
            } elseif ($status==='pending' && $rowStatus==='unwon') {
                $status='unwon';
            }
            if ($rowStatus==='pending') $status='pending';
        }
        if ($status!=='refunded') $status=$winAmount>0 ? 'won' : ($status==='pending' ? 'pending' : $status);
        Db::name('bet_submissions')->where('id',$submissionId)->update([
            'bet_count'=>$betCount,
            'amount'=>number_format($amount,2,'.',''),
            'win_amount'=>number_format($winAmount,2,'.',''),
            'status'=>$status,
            'sealed'=>$sealed,
            'refunded_at'=>$refundedAt,
        ]);
    }

    /** @return array{0:float,1:bool} */
    private function lockedOdds(array $detail,array $stop,int $lotteryId,int $numberCount): array
    {
        if($detail['odds']!==null){
            $odds=(float)$detail['odds'];
            if($odds<=0)throw new \RuntimeException('注单明细 #'.(int)$detail['id'].' 的锁定赔率无效，已停止整单结算');
            return [$odds,false];
        }
        if(($stop['actual_odds']??null)!==null&&(float)$stop['actual_odds']>0)return [(float)$stop['actual_odds'],true];
        $row=$this->uniqueLegacyOddsRow($lotteryId,(string)($detail['source_text']??''));
        if($row===[])throw new \RuntimeException('历史注单明细 #'.(int)$detail['id'].' 缺少锁定赔率，且无法唯一匹配旧赔率，已停止整单结算');
        $odds=(float)$row['odds']-(float)($stop['drop_odds']??0);
        if($odds<=0)throw new \RuntimeException('历史注单明细 #'.(int)$detail['id'].' 回退赔率无效，已停止整单结算');
        return [$odds,true];
    }

    /** @return array<string,mixed> */
    private function uniqueLegacyOddsRow(int $lotteryId,string $source): array
    {
        $identity=(new QuickEntryRules())->oddsIdentity($source);
        if($identity===null)return [];
        if($identity['direct']){
            $rows=Db::name('lottery_odds_categories')->where('lottery_id',$lotteryId)
                ->where('name',$identity['category'])->where('status',1)->where('is_playable',1)
                ->whereNull('deleted_at')->select()->toArray();
        }else{
            $rows=Db::name('lottery_odds')->where('lottery_id',$lotteryId)
                ->where('category',$identity['category'])->where('name',$identity['name'])
                ->where('status',1)->whereNull('deleted_at')->select()->toArray();
        }
        return count($rows)===1?$rows[0]:[];
    }

    /** Distribute this user's net house profit through the organization chain. */
    private function allocateOrganizationProfit(array $record, array $user, float $houseProfit): void
    {
        if (abs($houseProfit) < 0.000001) return;
        $nodeId = (int)($user['organization_id'] ?? 0);
        if ($nodeId < 1) {
            $root = OrganizationHierarchy::rootForSite((int)$record['site_id']);
            if (!$root) throw new \RuntimeException('结算站点未配置根总监，无法分配盈亏');
            $nodeId = (int)$root['id'];
        }
        $siteSettings=Db::name('sites')->where('id',(int)$record['site_id'])->value('settings');
        $siteSettings=is_string($siteSettings)?json_decode($siteSettings,true):(is_array($siteSettings)?$siteSettings:[]);
        $siteCap=max(0,min(100,(float)($siteSettings['max_profit_share_rate']??100)));
        $chain=[];$visited=[];
        while($nodeId>0&&!in_array($nodeId,$visited,true)){
            $visited[]=$nodeId;$node=Db::name('organization_nodes')->where('id',$nodeId)->whereNull('deleted_at')->lock(true)->find();if(!$node)break;
            $share=Db::name('organization_profit_shares')->where('child_organization_id',(int)$node['id'])->where('parent_organization_id',(int)$node['parent_id'])->where('status',1)->find();
            $node['share_rate']=$share?(float)$share['share_rate']:0.0;
            $chain[]=$node;$nodeId=(int)$node['parent_id'];
        }
        if($chain===[])return;
        foreach(SequentialProfitShare::allocate($houseProfit,$chain,$siteCap) as $allocation){
            $node=$allocation['node'];$amount=$allocation['amount'];
            if(abs($amount)<0.005)continue;
            $before=(float)$node['balance'];Db::name('organization_nodes')->where('id',(int)$node['id'])->update(['balance'=>Db::raw('balance + '.number_format($amount,2,'.','')),'updated_at'=>date('Y-m-d H:i:s')]);
            CreditLedger::organizationSettlement(
                $record,(int)$node['id'],$amount,$before,$before+$amount,
                $amount>=0?'本期投注盈利占成':'本期投注亏损承担',
                [
                    'allocation_method'=>'sequential_remainder',
                    'line_organization_id'=>(int)($user['organization_id']??0),
                    'organization_level'=>(string)($node['level']??''),
                    'incoming_amount'=>$allocation['incoming_amount'],
                    'share_rate'=>$allocation['share_rate'],
                    'share_amount'=>$allocation['amount'],
                    'remaining_amount'=>$allocation['remaining_amount'],
                ],
            );
        }
    }

    private function digits(array $history): string
    {
        $digits = '';
        foreach (['one', 'two', 'three'] as $key) $digits .= (string)($history[$key] ?? '');
        if (preg_match('/^\d{3}$/', $digits)) return $digits;
        return preg_replace('/\D/', '', (string)($history['numbers'] ?? '')) ?: '';
    }

    /**
     * Expanded group-three/group-six tokens are the outcomes of one package
     * bet, not independent stakes. The parent detail therefore keeps the
     * package amount and locked package odds. We still divide the amount over
     * its tokens for matching, then multiply the odds by the token count so a
     * single real combination pays exactly what the legacy 000 package paid.
     *
     * @param array<int,string> $numbers
     * @return array{matched:int,stake:float,effective_odds:float,win:float}
     */
    private function detailPayout(array $numbers,string $draw,string $source,float $amount,float $packageOdds): array
    {
        $numberCount=count($numbers);
        if($numberCount<1)throw new \InvalidArgumentException('结算号码不能为空');
        // Position bets are stored as one compact expression while the amount
        // is the sum of all generated combinations. Split it back to the
        // per-number stake before applying the odds; otherwise one hit would
        // incorrectly pay total_detail_amount × odds.
        $positionCount=$this->positionCombinationCount($source);
        $stake=$positionCount>1&&$numberCount===1?$amount/$positionCount:$amount/$numberCount;
        $matched=0;
        foreach($numbers as $number)if($this->matches($number,$draw,$source))$matched++;
        $effectiveOdds=$this->isExpandedGroupPackage($numbers,$source)?$packageOdds*$numberCount:$packageOdds;
        return ['matched'=>$matched,'stake'=>$stake,'effective_odds'=>$effectiveOdds,'win'=>$matched*$stake*$effectiveOdds];
    }

    private function positionCombinationCount(string $source): int
    {
        $counts=[];
        foreach (['百','十','个'] as $marker) {
            if (!preg_match('/'.$marker.'\s*([0-9]+)/u', $source, $match)) continue;
            $digits=array_values(array_unique(str_split((string)$match[1])));
            if ($digits !== []) $counts[] = count($digits);
        }
        if (count($counts) < 1) return 0;
        $total=1;
        foreach ($counts as $count) $total*=$count;
        return $total;
    }

    /** @param array<int,string> $numbers */
    private function isExpandedGroupPackage(array $numbers,string $source): bool
    {
        if(count($numbers)<2||!preg_match('/(?<!\d)([0-9]{1,10})\s*(组三|组六)[一二两三四五六七八九1-9]码/u',$source,$catalog))return false;
        $selected=array_values(array_unique(str_split($catalog[1])));
        $requiredUnique=$catalog[2]==='组三'?2:3;
        foreach($numbers as $number){
            $digits=array_values(array_unique(str_split($number)));
            if(strlen($number)!==3||count($digits)!==$requiredUnique||array_diff($digits,$selected)!==[])return false;
        }
        return true;
    }

    private function matches(string $number, string $draw, string $source): bool
    {
        // Standalone 胆 entries may contain several one-digit tokens (for
        // example “1独胆 6独胆”).  Match each persisted token to its own
        // digit; treating the whole string as one three-digit direct number
        // would make the second token reuse the first 胆 digit.
        if (preg_match('/独胆|(?<!\d)胆/u', $source) && preg_match('/^\d$/', trim($number)) === 1) {
            return str_contains($draw, trim($number));
        }
        $compactResult=$this->matchesCompactExpression($number,$draw,$source);
        if($compactResult!==null)return $compactResult;
        $sourceCompact=preg_replace('/\s+/u','',$source)??$source;
        $sum = array_sum(array_map('intval', str_split($draw)));
        if (str_contains($source, '和大')) return $sum >= 14;
        if (str_contains($source, '和小')) return $sum <= 13;
        if (str_contains($source, '和单')) return $sum % 2 === 1;
        if (str_contains($source, '和双')) return $sum % 2 === 0;
        if (preg_match('/跨度\s*([0-9])/u', $source, $match)) return max(str_split($draw)) - min(str_split($draw)) === (int)$match[1];
        if (preg_match('/和值\s*(2[0-7]|1\d|[0-9])\s*-\s*(2[0-7]|1\d|[0-9])/u', $source, $match)) {
            $endpoint=(int)$number;
            return in_array($endpoint,[(int)$match[1],(int)$match[2]],true)&&$sum===$endpoint;
        }
        if (preg_match('/和值\s*(2[0-7]|1\d|[0-9])(?!\s*-)/u', $source, $match)) return $sum === (int)$match[1];
        if (str_contains($source, '豹子全包')) return count(array_unique(str_split($draw))) === 1;
        if (str_contains($source, '对子全包')) return count(array_unique(str_split($draw))) === 2;
        if (str_contains($source, '组三全包')) return count(array_unique(str_split($draw))) === 2;
        if (str_contains($source, '组六全包')) return count(array_unique(str_split($draw))) === 3;
        if (preg_match('/单选全胆拖\s+胆(\d)拖(\d+)/u',$source,$drag)) {
            $drawDigits=str_split($draw);$allowed=array_values(array_unique(str_split($drag[1].$drag[2])));
            return in_array($drag[1],$drawDigits,true)&&array_diff($drawDigits,$allowed)===[]&&array_intersect($drawDigits,str_split($drag[2]))!==[];
        }
        if (preg_match('/(组三胆拖|组六胆拖)\s+胆(\d)拖(\d+)/u',$source,$drag)) {
            $drawDigits=array_values(array_unique(str_split($draw)));$banker=$drag[2];$dragDigits=str_split($drag[3]);
            if(!in_array($banker,$drawDigits,true))return false;
            $others=array_values(array_diff($drawDigits,[$banker]));
            return count($drawDigits)===($drag[1]==='组三胆拖'?2:3)&&array_diff($others,$dragDigits)===[];
        }
        if (preg_match('/组六2胆拖\s+胆(\d{2})拖(\d+)/u',$source,$drag)) {
            $drawDigits=array_values(array_unique(str_split($draw)));$bankers=array_values(array_unique(str_split($drag[1])));$dragDigits=str_split($drag[2]);
            return count($drawDigits)===3&&array_diff($bankers,$drawDigits)===[]&&array_diff($drawDigits,$bankers,$dragDigits)===[];
        }
        if (preg_match('/(?<!\d)([0-9]{1,10})\s*(组三赖|组六赖|组三|组六|复式)[一二两三四五六七八九1-9]码/u', $source, $catalog)) {
            $selected = array_values(array_unique(str_split($catalog[1])));
            $drawDigits = array_values(array_unique(str_split($draw)));
            $intersects = array_intersect($drawDigits, $selected) !== [];
            $contained = array_diff($drawDigits, $selected) === [];
            if ($catalog[2] === '组三赖') return count($drawDigits) === 2 && $intersects;
            if ($catalog[2] === '组六赖') return count($drawDigits) === 3 && $intersects;
            if ($catalog[2] === '组三') {
                if($number!=='000')return count($drawDigits)===2&&count(array_unique(str_split($number)))===2&&$contained&&count_chars($number,1)===count_chars($draw,1);
                return count($drawDigits) === 2 && $contained;
            }
            if ($catalog[2] === '组六') {
                if($number!=='000')return count($drawDigits)===3&&count(array_unique(str_split($number)))===3&&$contained&&count_chars($number,1)===count_chars($draw,1);
                return count($drawDigits) === 3 && $contained;
            }
            return $contained;
        }
        if (str_contains($source, '豹子')) return count(array_unique(str_split($draw))) === 1 && $number === $draw;
        if (str_contains($source, '组三')) return count(array_unique(str_split($draw))) === 2 && count(array_unique(str_split($number))) === 2 && count_chars($number, 1) === count_chars($draw, 1);
        if (str_contains($source, '组六')) return count(array_unique(str_split($draw))) === 3 && count(array_unique(str_split($number))) === 3 && count_chars($number, 1) === count_chars($draw, 1);
        if (str_contains($source, '双飞')) {
            $pair=preg_match('/(?<!\d)(\d{2})\s*(?:双飞|飞)/u',$sourceCompact,$pairMatch)?str_split($pairMatch[1]):str_split(substr($number,-2));
            if(count($pair)!==2)return false;
            if($pair[0]===$pair[1])return substr_count($draw,$pair[0])>=2;
            return str_contains($draw,$pair[0])&&str_contains($draw,$pair[1]);
        }
        if (str_contains($source, '对子')) {
            $pair=preg_match('/(?<!\d)(\d{2})\s*(?:对子|对)/u',$sourceCompact,$pairMatch)?$pairMatch[1]:substr($number,-2);
            return $pair!==''&&substr_count($draw,$pair[0])>=2;
        }
        foreach(['口XX'=>[0],'X口X'=>[1],'XX口'=>[2],'口口X'=>[0,1],'口X口'=>[0,2],'X口口'=>[1,2]] as $pattern=>$indexes){
            if(!str_contains($source,$pattern))continue;
            foreach($indexes as $index)if(($number[$index]??'')!==($draw[$index]??''))return false;
            return true;
        }
        if (preg_match_all('/[百十个]/u', $source, $positions) >= 1) {
            foreach (array_values(array_unique($positions[0])) as $position) {
                $index = ['百' => 0, '十' => 1, '个' => 2][$position];
                if (($number[$index] ?? '') !== ($draw[$index] ?? '')) return false;
            }
            if (preg_match('/^\d{3}$/', $number) === 1 && $number !== '000') {
                foreach (array_values(array_unique($positions[0])) as $position) {
                    $index = ['百' => 0, '十' => 1, '个' => 2][$position];
                    if (($number[$index] ?? '') !== ($draw[$index] ?? '')) return false;
                }
            }
            return true;
        }
        if (preg_match('/(?:独胆|胆)\s*(\d)/u', $source, $match)) return str_contains($draw, $match[1]);
        return $number === $draw;
    }

    /**
     * Match the compact expression stored by the detail parser. These values
     * deliberately describe one play instead of an expanded list of 3-digit
     * combinations (for example 三123456, 66飞, 和小, 457组 and 874直).
     */
    private function matchesCompactExpression(string $number,string $draw,string $source): ?bool
    {
        $expression=trim($number);
        if($expression==='')return null;
        $compact=preg_replace('/\s+/u','',$expression)??$expression;
        $drawDigits=str_split($draw);
        $drawUnique=array_values(array_unique($drawDigits));
        $sum=array_sum(array_map('intval',$drawDigits));
        $sourceCompact=preg_replace('/\s+/u','',$source)??$source;

        if(preg_match('/^三([0-9]{2,10})$/u',$compact,$match)){
            $selected=array_values(array_unique(str_split($match[1])));
            return count($drawUnique)===2&&array_diff($drawUnique,$selected)===[];
        }
        if(preg_match('/^六([0-9]{3,10})$/u',$compact,$match)){
            $selected=array_values(array_unique(str_split($match[1])));
            return count($drawUnique)===3&&array_diff($drawUnique,$selected)===[];
        }
        if(preg_match('/^三赖([0-9]{1,10})$/u',$compact,$match)){
            $selected=array_values(array_unique(str_split($match[1])));
            return count($drawUnique)===2&&array_intersect($drawUnique,$selected)!==[];
        }
        if(preg_match('/^六赖([0-9]{1,10})$/u',$compact,$match)){
            $selected=array_values(array_unique(str_split($match[1])));
            return count($drawUnique)===3&&array_intersect($drawUnique,$selected)!==[];
        }
        if(preg_match('/^豹([0-9]{1,10})$/u',$compact,$match)){
            $selected=array_values(array_unique(str_split($match[1])));
            return count($drawUnique)===1&&array_diff($drawUnique,$selected)===[];
        }
        if(preg_match('/^([0-9]{2,10})组$/u',$compact,$match)){
            if(strlen($match[1])===3){
                $expected=count(array_unique(str_split($match[1])));
                return count($drawUnique)===$expected && count_chars($draw,1)===count_chars($match[1],1);
            }
            $selected=array_values(array_unique(str_split($match[1])));
            if(count($selected)===2)return count($drawUnique)===2&&array_diff($drawUnique,$selected)===[];
            return count($selected)>=3&&count($drawUnique)===3&&array_diff($drawUnique,$selected)===[];
        }
        if(preg_match('/^([0-9]{3})直$/u',$compact,$match))return $draw===$match[1];
        if(preg_match('/^([0-9]{2})(?:飞|双飞)$/u',$compact,$match)){
            $digits=str_split($match[1]);
            if($digits[0]===$digits[1])return substr_count($draw,$digits[0])>=2;
            return in_array($digits[0],$drawDigits,true)&&in_array($digits[1],$drawDigits,true);
        }
        if(preg_match('/^([0-9]{2})(?:对|对子)$/u',$compact,$match))return substr_count($draw,$match[1][0])>=2;
        if(preg_match('/^(?:和|和值)(大|小|单|双)$/u',$compact,$match))return match($match[1]){'大'=>$sum>=14,'小'=>$sum<=13,'单'=>$sum%2===1,'双'=>$sum%2===0};
        if(preg_match('/^(?:和|和值)(\d{1,2})$/u',$compact,$match))return $sum===(int)$match[1];
        if(preg_match('/^(?:和|和值)(\d{1,2})\s*-\s*(\d{1,2})$/u',$compact,$match))return $sum===(int)$match[1]||$sum===(int)$match[2];
        if(preg_match('/^(?:跨|跨度)(\d)$/u',$compact,$match))return max($drawDigits)-min($drawDigits)===(int)$match[1];
        if(preg_match('/^胆(\d+)拖(\d+)$/u',$compact,$match)){
            $dan=array_values(array_unique(str_split($match[1])));$tuo=array_values(array_unique(str_split($match[2])));
            $allowed=array_values(array_unique(array_merge($dan,$tuo)));
            if(array_diff($dan,$drawDigits)!==[])return false;
            $family=str_contains($sourceCompact,'组三胆拖')?'z3':(str_contains($sourceCompact,'组六2胆拖')?'z6_2':(str_contains($sourceCompact,'单选全胆拖')?'single':'z6'));
            if($family==='single')return array_diff($drawDigits,$allowed)===[]&&array_intersect($drawDigits,$tuo)!==[];
            if($family==='z6_2')return count($drawUnique)===3&&count($dan)===2&&array_diff($dan,$drawUnique)===[]&&count(array_intersect($drawUnique,$tuo))>=1;
            $required=$family==='z3'?2:3;
            $otherUnique=array_values(array_diff($drawUnique,$dan));
            return count($drawUnique)===$required&&array_diff($drawUnique,$allowed)===[]&&array_intersect($otherUnique,$tuo)!==[];
        }
        if(in_array($compact,['豹子','豹子全包'],true))return count($drawUnique)===1;
        if(in_array($compact,['对子','对子全包'],true))return count($drawUnique)===2;
        if($compact==='组三全包')return count($drawUnique)===2;
        if($compact==='组六全包')return count($drawUnique)===3;
        if(preg_match('/^复([0-9]{3,10})$/u',$compact,$match)){
            $selected=array_values(array_unique(str_split($match[1])));
            // 复式覆盖选中号码集合内的所有三位结果，不能套用组六的“三个不同数字”限制。
            return array_diff($drawUnique,$selected)===[];
        }

        // Legacy rows may only keep the selected digits in source_text while
        // number_text is the 000 placeholder. Keep the same rules for both
        // play-before-number and number-before-play wording.
        if (preg_match('/(?<!\d)([0-9]{2,10})\s*(组三赖|组六赖|组三|组六|复式)/u', $sourceCompact, $catalog)
            || preg_match('/(组三赖|组六赖|组三|组六|复式)\s*([0-9]{2,10})/u', $sourceCompact, $catalogBefore)) {
            if (!empty($catalogBefore)) {
                $selectedText=(string)$catalogBefore[2];
                $family=(string)$catalogBefore[1];
            } else {
                $selectedText=(string)$catalog[1];
                $family=(string)$catalog[2];
            }
            $selected=array_values(array_unique(str_split($selectedText)));
            // Legacy rows use 000 as a placeholder and reach this branch.
            // Keep their 复式 semantics identical to compact rows.
            if ($family==='复式') return array_diff($drawUnique,$selected)===[];
            $required=$family==='组三'||$family==='组三赖'?2:3;
            if ($family==='组三赖'||$family==='组六赖') return count($drawUnique)===$required&&array_intersect($drawUnique,$selected)!==[];
            if (in_array($family,['组三','组六'],true) && preg_match('/^\d{3}$/',$number)===1 && $number!=='000') {
                return count($drawUnique)===$required&&array_diff($drawUnique,$selected)===[]&&count_chars($number,1)===count_chars($draw,1);
            }
            return count($drawUnique)===$required&&array_diff($drawUnique,$selected)===[];
        }

        // Position bets can be stored as 1D/2D/3D or as a digit followed by
        // “定位”. When position labels are present, check each position's
        // selected digits; otherwise a one-position bet means the digit is
        // present somewhere in the draw.
        if(str_contains($source,'定位')||preg_match('/[百十个]/u',$compact)||preg_match('/[百十个]/u',$source)||preg_match('/^[0-9]+D$/i',$compact)){
            $positionRules=[];
            foreach(['百'=>0,'十'=>1,'个'=>2] as $marker=>$index){
                if(preg_match('/'.$marker.'(?:位)?\s*([0-9]+)/u',$source,$match))$positionRules[$index]=array_values(array_unique(str_split($match[1])));
            }
            if($positionRules!==[]){
                foreach($positionRules as $index=>$allowed)if(!isset($draw[$index])||!in_array($draw[$index],$allowed,true))return false;
                // Expanded position selections contain every permitted
                // combination. Only the exact drawn combination wins.
                if (preg_match('/^\d{3}$/', $number) === 1 && $number !== '000') {
                    foreach (array_keys($positionRules) as $index) if (($number[$index] ?? '') !== ($draw[$index] ?? '')) return false;
                }
                return true;
            }
            $dMatch=[];
            $digits=preg_match('/^([0-9]+)D$/i',$compact,$dMatch)?(string)$dMatch[1]:'';
            if($digits!==''){
                $required=1;
                if(str_contains($source,'二码定位'))$required=2;
                if(str_contains($source,'三码定位'))$required=3;
                return count(array_intersect(array_values(array_unique(str_split($digits))),$drawDigits)) >= $required;
            }
            if(preg_match('/^(一|二|三)码?定位$/u',$compact,$match)){
                $required=['一'=>1,'二'=>2,'三'=>3][$match[1]];
                $sourceDigits=preg_replace('/\D/','',$source)??'';
                return $sourceDigits!=='' && count(array_intersect(array_values(array_unique(str_split($sourceDigits))),$drawDigits)) >= $required;
            }
        }
        if(str_contains($sourceCompact,'独胆')||preg_match('/^\d胆$/u',$compact)){
            $digits=preg_match('/(?<!\d)(\d)\s*(?:独胆|胆)/u',$sourceCompact,$sourceDan)?$sourceDan[1]:preg_replace('/\D/','',$compact);
            if($digits!=='')return str_contains($draw,$digits[0]);
        }
        return null;
    }

    public function numberMatches(string $number, string $drawNumbers, string $source): bool
    {
        $draw = preg_replace('/\D/', '', $drawNumbers) ?: '';
        return strlen($draw) === 3 && $this->matches($number, $draw, $source);
    }

    public function oddsFor(int $lotteryId, string $source, int $count): float
    {
        $row = $this->oddsRowFor($lotteryId, $source);
        return $row ? (float)$row['odds'] : 0.0;
    }

    /** @return array<string, mixed> */
    public function oddsRowFor(int $lotteryId, string $source): array
    {
        $identity = (new QuickEntryRules())->oddsIdentity($source);
        if ($identity === null) return [];
        if ($identity['direct']) {
            $row = Db::name('lottery_odds_categories')->where('lottery_id', $lotteryId)
                ->where('name', $identity['category'])->where('status', 1)->where('is_playable', 1)
                ->whereNull('deleted_at')->find();
            if (!$row) return [];
            $row['id'] = 1000000000 + (int)$row['id'];
            $row['category'] = $identity['category'];
            $row['name'] = $identity['name'];
            return $row;
        }
        return Db::name('lottery_odds')->where('lottery_id', $lotteryId)
            ->where('category', $identity['category'])->where('name', $identity['name'])
            ->where('status', 1)->whereNull('deleted_at')->find() ?: [];
    }
}
