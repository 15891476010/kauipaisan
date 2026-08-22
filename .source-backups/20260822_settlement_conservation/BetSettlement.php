<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class BetSettlement
{
    public function settleForHistory(array $history, array $lottery): array
    {
        $lotteryId = (int)($lottery['id'] ?? 0);
        $issue = trim((string)($history['code'] ?? ''));
        if ($lotteryId < 1 || $issue === '') return ['records' => 0, 'won' => 0];
        $lotteryName = (string)($lottery['name'] ?? '');
        $draw = $this->digits($history);
        if ($draw === '') return ['records' => 0, 'won' => 0];

        $records = Db::name('bet_records')->where('issue_no', $issue)->where('status', 'pending')->select()->toArray();
        $processed = 0; $won = 0;
        foreach ($records as $record) {
            $details = Db::name('bet_details')->where('bet_record_id', (int)$record['id'])->select()->toArray();
            if ($details === []) continue;
            $totalWin = 0.0;
            $totalRebate = 0.0;
            $matchedLottery = false;
            foreach ($details as $detail) {
                $stop = Db::name('user_stop_drops')->where('bet_detail_id', (int)$detail['id'])->find();
                if (!$stop || (string)$stop['lottery'] !== $lotteryName) continue;
                $matchedLottery = true;
                $numbers = preg_split('/\s+/', trim((string)$detail['number_text'])) ?: [];
                $numbers = array_values(array_filter($numbers, static fn(string $n): bool => preg_match('/^\d{3}$/', $n) === 1));
                if ($numbers === []) continue;
                $odds = $this->oddsFor($lotteryId, (string)($detail['source_text'] ?? ''), count($numbers));
                $drop=(float)($stop['drop_odds']??0); $odds=max(0,$odds-$drop);
                $stake = (float)$detail['amount'] / max(1, count($numbers));
                $matched = 0;
                foreach ($numbers as $number) if ($this->matches($number, $draw, (string)($detail['source_text'] ?? ''))) $matched++;
                $win = $matched * $stake * $odds;
                $totalWin += $win;
                $totalRebate += (float)($detail['rebate'] ?? 0);
                Db::name('bet_details')->where('id', (int)$detail['id'])->update([
                    'odds' => number_format($odds, 4, '.', ''),
                    'win_amount' => number_format($win, 2, '.', ''),
                    'status' => $win > 0 ? 'won' : 'unwon',
                ]);
                Db::name('user_stop_drops')->where('bet_detail_id', (int)$detail['id'])->update([
                    'actual_odds' => number_format($odds, 3, '.', ''),
                ]);
            }
            if (!$matchedLottery) continue;
            $status = $totalWin > 0 ? 'won' : 'unwon';
            $settled=Db::transaction(function () use ($record, $totalWin, $totalRebate, $status): bool {
                $lockedRecord=Db::name('bet_records')->where('id',(int)$record['id'])->lock(true)->find();
                if(!$lockedRecord || (string)$lockedRecord['status']!=='pending') return false;
                Db::name('bet_records')->where('id', (int)$record['id'])->update([
                    'win_amount' => number_format($totalWin, 2, '.', ''),
                    'status' => $status,
                ]);
                $userId = (int)$record['user_id']; $siteId = (int)$record['site_id'];
                $amount = (float)$record['amount'];
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
                CreditLedger::userSettlement(array_merge($record, ['organization_id'=>$user['organization_id'] ?? null]), $totalWin, $availableBefore, $availableBefore + $totalWin);
                $houseProfit = $amount - $totalWin - $totalRebate;
                $this->allocateOrganizationProfit($record, $user, $houseProfit);
                $billDate = substr((string)$record['placed_at'], 0, 10);
                $bill = Db::name('bills')->where('site_id', $siteId)->where('user_id', $userId)->where('bill_date', $billDate)->find();
                if ($bill) Db::name('bills')->where('id', (int)$bill['id'])->update([
                    'bet_count' => (int)$bill['bet_count'] + (int)$record['bet_count'],
                    'amount' => number_format((float)$bill['amount'] + $amount, 2, '.', ''),
                    'win_amount' => number_format((float)$bill['win_amount'] + $totalWin, 2, '.', ''),
                    'profit' => number_format((float)$bill['profit'] + $totalWin - $amount, 2, '.', ''),
                ]);
                else Db::name('bills')->insert([
                    'tenant_id' => (int)$record['tenant_id'], 'site_id' => $siteId, 'user_id' => $userId,
                    'bill_date' => $billDate, 'bet_count' => (int)$record['bet_count'], 'amount' => number_format($amount, 2, '.', ''),
                    'rebate' => '0.00', 'offline_rebate' => '0.00', 'win_amount' => number_format($totalWin, 2, '.', ''),
                    'profit' => number_format($totalWin - $amount, 2, '.', ''), 'created_at' => date('Y-m-d H:i:s'),
                ]);
                return true;
            });
            if($settled){$processed++; if ($totalWin > 0) $won++;}
        }
        return ['records' => $processed, 'won' => $won];
    }

    /** Distribute this user's net house profit through the organization chain. */
    private function allocateOrganizationProfit(array $record, array $user, float $houseProfit): void
    {
        $nodeId = (int)($user['organization_id'] ?? 0);
        if ($nodeId < 1 || abs($houseProfit) < 0.000001) return;
        $siteSettings=Db::name('sites')->where('id',(int)$record['site_id'])->value('settings');
        $siteSettings=is_string($siteSettings)?json_decode($siteSettings,true):(is_array($siteSettings)?$siteSettings:[]);
        $siteCap=max(0,min(100,(float)($siteSettings['max_profit_share_rate']??100)));
        $chain=[];$visited=[];
        while($nodeId>0&&!in_array($nodeId,$visited,true)){
            $visited[]=$nodeId;$node=Db::name('organization_nodes')->where('id',$nodeId)->whereNull('deleted_at')->lock(true)->find();if(!$node)break;$chain[]=$node;$nodeId=(int)$node['parent_id'];
        }
        if($chain===[])return;
        $chain=array_reverse($chain);$effective=[];$currentRate=100.0;
        foreach($chain as $index=>$node){
            $share=Db::name('organization_profit_shares')->where('child_organization_id',(int)$node['id'])->where('parent_organization_id',(int)$node['parent_id'])->where('status',1)->find();
            $edgeRate=$share?max(0,min($siteCap,(float)$share['share_rate'])):($index===0?$siteCap:0.0);
            $currentRate*=$edgeRate/100;$effective[$index]=$currentRate;
        }
        $distributed=0.0;$last=count($chain)-1;
        foreach($chain as $index=>$node){
            $ownedRate=$effective[$index]-($index<$last?$effective[$index+1]:0.0);
            $amount=$index===$last?round($houseProfit-$distributed-round($houseProfit*(100-$effective[0])/100,2),2):round($houseProfit*$ownedRate/100,2);
            $distributed+=$amount;
            if(abs($amount)<0.005)continue;
            $before=(float)$node['balance'];Db::name('organization_nodes')->where('id',(int)$node['id'])->update(['balance'=>Db::raw('balance + '.number_format($amount,2,'.','')),'updated_at'=>date('Y-m-d H:i:s')]);
            CreditLedger::organizationSettlement($record,(int)$node['id'],$amount,$before,$before+$amount,$amount>=0?'本期投注盈利分成':'本期投注亏损承担');
        }
        $platformAmount=round($houseProfit-$distributed,2);
        if(abs($platformAmount)>=0.005)$this->applyPlatformProfit($record,$platformAmount);
    }

    private function applyPlatformProfit(array $record,float $amount): void
    {
        $tenantId=(int)$record['tenant_id'];$now=date('Y-m-d H:i:s');$account=Db::name('platform_credit_accounts')->where('tenant_id',$tenantId)->lock(true)->find();
        if(!$account){$id=(int)Db::name('platform_credit_accounts')->insertGetId(['tenant_id'=>$tenantId,'balance'=>'0.00','created_at'=>$now,'updated_at'=>$now]);$account=['id'=>$id,'balance'=>0];}
        $before=(float)$account['balance'];Db::name('platform_credit_accounts')->where('id',(int)$account['id'])->update(['balance'=>Db::raw('balance + '.number_format($amount,2,'.','')),'updated_at'=>$now]);
        CreditLedger::platformSettlement($record,(int)$account['id'],$amount,$before,$before+$amount);
    }

    private function digits(array $history): string
    {
        $digits = '';
        foreach (['one', 'two', 'three'] as $key) $digits .= (string)($history[$key] ?? '');
        if (preg_match('/^\d{3}$/', $digits)) return $digits;
        return preg_replace('/\D/', '', (string)($history['numbers'] ?? '')) ?: '';
    }

    private function matches(string $number, string $draw, string $source): bool
    {
        $sum = array_sum(array_map('intval', str_split($draw)));
        if (str_contains($source, '和大')) return $sum >= 14;
        if (str_contains($source, '和小')) return $sum <= 13;
        if (str_contains($source, '和单')) return $sum % 2 === 1;
        if (str_contains($source, '和双')) return $sum % 2 === 0;
        if (preg_match('/跨度\s*([0-9])/u', $source, $match)) return max(str_split($draw)) - min(str_split($draw)) === (int)$match[1];
        if (preg_match('/和值\s*(2[0-7]|1\d|[0-9])(?!\s*-)/u', $source, $match)) return $sum === (int)$match[1];
        if (str_contains($source, '豹子全包')) return count(array_unique(str_split($draw))) === 1;
        if (str_contains($source, '对子全包')) return count(array_unique(str_split($draw))) === 2;
        if (str_contains($source, '组三全包')) return count(array_unique(str_split($draw))) === 2;
        if (str_contains($source, '组六全包')) return count(array_unique(str_split($draw))) === 3;
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
        if (preg_match('/(?<!\d)([0-9]{1,10})\s*(组三赖|组六赖|组三|组六|复式)[一二两三四五六七八九]码/u', $source, $catalog)) {
            $selected = array_values(array_unique(str_split($catalog[1])));
            $drawDigits = array_values(array_unique(str_split($draw)));
            $intersects = array_intersect($drawDigits, $selected) !== [];
            $contained = array_diff($drawDigits, $selected) === [];
            if ($catalog[2] === '组三赖') return count($drawDigits) === 2 && $intersects;
            if ($catalog[2] === '组六赖') return count($drawDigits) === 3 && $intersects;
            if ($catalog[2] === '组三') return count($drawDigits) === 2 && $contained;
            if ($catalog[2] === '组六') return count($drawDigits) === 3 && $contained;
            return $contained;
        }
        if (str_contains($source, '豹子')) return count(array_unique(str_split($draw))) === 1 && $number === $draw;
        if (str_contains($source, '组三')) return count(array_unique(str_split($draw))) === 2 && count(array_unique(str_split($number))) === 2 && count_chars($number, 1) === count_chars($draw, 1);
        if (str_contains($source, '组六')) return count(array_unique(str_split($draw))) === 3 && count(array_unique(str_split($number))) === 3 && count_chars($number, 1) === count_chars($draw, 1);
        if (str_contains($source, '双飞')) {
            $digits = array_values(array_unique(str_split(substr($number, -2))));
            return count($digits) === 2 && str_contains($draw, $digits[0]) && str_contains($draw, $digits[1]);
        }
        if (str_contains($source, '对子')) return substr_count($draw, substr($number, -1)) >= 2;
        if (preg_match_all('/[百十个]/u', $source, $positions) >= 1) {
            foreach (array_values(array_unique($positions[0])) as $position) {
                $index = ['百' => 0, '十' => 1, '个' => 2][$position];
                if (($number[$index] ?? '') !== ($draw[$index] ?? '')) return false;
            }
            return true;
        }
        if (preg_match('/(\d)\s*(?:独胆|胆)/u', $source, $match)) return str_contains($draw, $match[1]);
        return $number === $draw;
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
