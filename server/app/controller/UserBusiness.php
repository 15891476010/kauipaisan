<?php
declare(strict_types=1);
namespace app\controller;

use app\service\InterceptionAllocator;
use app\service\CreditLedger;
use app\service\BetSettlement;
use app\service\LotteryHistorySync;
use app\service\SystemLotteryService;
use app\service\QuickEntryParser;
use think\Request;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;

final class UserBusiness
{
    private function reply(mixed $data=null, string $message='ok', int $code=0): \think\response\Json { return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]); }
    private function session(Request $request): array
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token !== '' ? Cache::get('token:'.$token) : null;
        if (!is_array($session) || ($session['scope'] ?? '') !== 'user') throw new \RuntimeException('未登录或登录已过期');
        $siteId=(int)($session['site_id'] ?? 0); $userId=(int)($session['user_id'] ?? 0);
        if ($siteId < 1 || $userId < 1) throw new \RuntimeException('用户会话无效');
        $tenantId=(int)($session['tenant_id'] ?? 0);
        if ($tenantId < 1) $tenantId=(int)Db::name('sites')->where('id',$siteId)->value('tenant_id');
        if ($tenantId < 1) throw new \RuntimeException('用户租户信息无效');
        return ['tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$userId];
    }

    public function lineOptions(Request $request): \think\response\Json
    {
        $session=$this->session($request);
        $rows=Db::name('domains')
            ->where('tenant_id',$session['tenant_id'])
            ->where('site_id',$session['site_id'])
            ->where('domain_type','user')
            ->where('status',1)
            ->order('is_primary desc')
            ->order('id asc')
            ->field('domain')
            ->select()->toArray();
        $lines=[];
        foreach ($rows as $row) {
            $domain=trim((string)($row['domain']??''));
            if ($domain==='') continue;
            if (!preg_match('/^https?:\/\//i',$domain)) $domain='https://'.$domain;
            $lines[]=['url'=>rtrim($domain,'/'),'line'=>count($lines)+1];
        }
        return $this->reply(['list'=>$lines]);
    }
    private function range(Request $request): array
    {
        $from=trim((string)$request->param('from','')); $to=trim((string)$request->param('to',''));
        return [$from !== '' ? $from.' 00:00:00' : null, $to !== '' ? $to.' 23:59:59' : null];
    }
    private function lotteryControl(array $session, string $lottery): array
    {
        $row=Db::name('lotteries')->alias('l')->join('site_lotteries sl','sl.lottery_id=l.id')
            ->where('sl.site_id',$session['site_id'])->where('sl.tenant_id',$session['tenant_id'])
            ->where('l.tenant_id',$session['tenant_id'])->where('l.name',$lottery)->whereNull('l.deleted_at')
            ->field('l.id,l.cutoff_enabled,l.cutoff_time,l.mask_enabled,l.refund_enabled')->find();
        if (!is_array($row)) return [];
        $siteControls=json_decode((string)Db::name('settings')->where('site_id',$session['site_id'])->where('key','lottery_betting_controls')->value('value'),true);
        $siteControl=is_array($siteControls)?($siteControls[(string)$row['id']]??[]):[];
        if (is_array($siteControl)) foreach (['cutoff_enabled','cutoff_time','mask_enabled','refund_enabled','timing_rules'] as $field) if (array_key_exists($field,$siteControl)) $row[$field]=$siteControl[$field];
        return $row;
    }
    private function cutoffReached(array $control, ?int $now=null): bool
    {
        if ((int)($control['cutoff_enabled']??0)!==1) return false;
        $time=trim((string)($control['cutoff_time']??''));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$time)) return false;
        $now ??= time();
        [$hour,$minute]=array_map('intval',explode(':',$time));
        $today=(new \DateTimeImmutable('today'))->setTime($hour,$minute)->getTimestamp();
        return $now >= $today;
    }
    private function cutoffConfigured(array $control): bool
    {
        return (int)($control['cutoff_enabled']??0)===1
            && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',trim((string)($control['cutoff_time']??'')))===1;
    }
    private function timingState(array $control, ?int $now=null): array
    {
        $now ??= time(); $minutes=(int)date('H',$now)*60+(int)date('i',$now); $matched=null;
        foreach((array)($control['timing_rules']??[]) as $rule){ if(!is_array($rule)) continue; [$sh,$sm]=array_map('intval',explode(':',(string)($rule['start_time']??'00:00'))); [$eh,$em]=array_map('intval',explode(':',(string)($rule['end_time']??'23:59'))); $start=$sh*60+$sm; $end=$eh*60+$em; $in=$start===$end?true:($start<$end?($minutes>=$start&&$minutes<$end):($minutes>=$start||$minutes<$end)); if($in){$matched=$rule;break;} }
        if($matched!==null)return ['allow_bet'=>(int)($matched['allow_bet']??1)===1,'mask_enabled'=>(int)($matched['mask_enabled']??0)===1,'show_next_issue'=>(int)($matched['show_next_issue']??1)===1,'display_text'=>(string)($matched['display_text']??'')];
        return ['allow_bet'=>true,'mask_enabled'=>(int)($control['mask_enabled']??1)!==0,'show_next_issue'=>true,'display_text'=>''];
    }
    private function timingAllowsBet(array $control): bool { return $this->timingState($control)['allow_bet']; }
    private function lineOdds(array $session, string $lottery, array $line): array
    {
        $lotteryRow=Db::name('lotteries')->where('tenant_id',$session['tenant_id'])->where('name',$lottery)->whereNull('deleted_at')->field('id')->find();
        if (!$lotteryRow) return [];
        $lotteryId=(int)$lotteryRow['id'];
        $source=(string)($line['settlement_text']??$line['raw_text']??'');
        $matched=(new \app\service\BetSettlement())->oddsRowFor($lotteryId,$source);
        if (!$matched) return [];
        $matched['platform_single_item_limit']=$matched['single_item_limit']??0;
        $override=Db::name('user_lottery_odds')->where('site_id',$session['site_id'])->where('user_id',$session['user_id'])->where('lottery_odds_id',(int)$matched['id'])->find();
        if ($override) foreach (['min_bet','odds_limit','single_bet_limit','single_item_limit','odds','offline_rebate'] as $field) if (array_key_exists($field,$override)) $matched[$field]=$override[$field];
        return $matched;
    }
    private function applyLineLimits(array $session, string $lottery, array $line): array
    {
        $requested=(float)($line['amount']??0); $count=max(1,(int)($line['stake_count']??$line['count']??1)); $odds=$this->lineOdds($session,$lottery,$line);
        if (!$odds) throw new \InvalidArgumentException('当前玩法无法唯一匹配赔率，已禁止下注');
        $actual=$requested;
        $minimum=(float)($odds['min_bet']??0);$perNumber=$requested/$count;
        if($minimum>0&&$perNumber+0.000001<$minimum)throw new \InvalidArgumentException('每个号码最小下注金额为 '.rtrim(rtrim(number_format($minimum,2,'.',''),'0'),'.'));
        $singleBet=(float)($odds['single_bet_limit']??0); $singleItem=(float)($odds['single_item_limit']??0);
        if ($singleBet>0) $actual=min($actual,$singleBet*$count);
        if ($singleItem>0) $actual=min($actual,$singleItem);
        $rebate=max(0,(float)($odds['offline_rebate']??0)); $rawOdds=$odds['odds']??null; $baseOdds=$rawOdds!==null && is_numeric($rawOdds)?(float)$rawOdds:null;
        $oddsLimit=max(0,(float)($odds['odds_limit']??0));if($baseOdds!==null&&$oddsLimit>0)$baseOdds=min($baseOdds,$oddsLimit);
        $actualOdds=$baseOdds===null?null:max(0,$baseOdds-$rebate);
        if($actualOdds!==null&&$actualOdds<=0)throw new \InvalidArgumentException('当前玩法有效赔率为0，已禁止下注');
        $stopAmount=max(0,$requested-$actual); $stopType=$stopAmount>0.0001?'stop':($rebate>0.000001?'drop':'none');
        return ['requested'=>$requested,'actual'=>$actual,'stop_amount'=>$stopAmount,'stop_type'=>$stopType,'original_odds'=>$baseOdds,'actual_odds'=>$actualOdds,'drop_odds'=>$rebate,'odds_row'=>$odds];
    }
    private function betSubmissionsAvailable(): bool
    {
        static $available = null;
        if ($available !== null) return $available;
        try { $available = Db::query("SHOW TABLES LIKE 'bet_submissions'") !== []; }
        catch (\Throwable) { $available = false; }
        return $available;
    }
    public function betRecords(Request $request): \think\response\Json
    {
        $s=$this->session($request); [$from,$to]=$this->range($request);
        if (!$this->betSubmissionsAvailable()) {
            $query=Db::name('bet_records')->where('site_id',$s['site_id'])->where('user_id',$s['user_id']);
            if ($from) $query->where('placed_at','>=',$from); if ($to) $query->where('placed_at','<=',$to);
            $status=(string)$request->param('status',''); if (in_array($status,['won','unwon'],true)) $query->where('status',$status);
            $source=trim((string)$request->param('source','')); if ($source !== '') $query->where(function($nested)use($source):void{$nested->whereLike('source_text','%'.$source.'%')->whereOrLike('formatted_text','%'.$source.'%');});
            $total=(clone $query)->count(); $amountTotal=(float)(clone $query)->sum('amount'); $page=max(1,(int)$request->param('page',1)); $size=min(100,max(1,(int)$request->param('page_size',20)));
            $list=$query->order('placed_at','desc')->page($page,$size)->select()->toArray();
            foreach ($list as &$record) {
                $refundState=$this->betSubmissionRefundState($record);
                $record['lottery']=$refundState['lottery'];
                $record['open_time']=$refundState['open_time'];
                $record['can_refund']=$refundState['can_refund'];
                $record['amount']=number_format((float)$record['amount'],2,'.','');
                $record['win_amount']=number_format((float)$record['win_amount'],2,'.','');
            }
            return $this->reply(['list'=>$list,'total'=>$total,'amount_total'=>number_format($amountTotal,2,'.',''),'page'=>$page,'page_size'=>$size]);
        }
        $query=Db::name('bet_submissions')->where('site_id',$s['site_id'])->where('user_id',$s['user_id']);
        if ($from) $query->where('placed_at','>=',$from); if ($to) $query->where('placed_at','<=',$to);
        $status=(string)$request->param('status',''); if (in_array($status,['won','unwon'],true)) $query->where('status',$status);
        $source=trim((string)$request->param('source','')); if ($source !== '') $query->where(function($nested)use($source):void{$nested->whereLike('source_text','%'.$source.'%')->whereOrLike('formatted_text','%'.$source.'%');});
        $total=(clone $query)->count(); $amountTotal=(float)(clone $query)->sum('amount'); $page=max(1,(int)$request->param('page',1)); $size=min(100,max(1,(int)$request->param('page_size',20)));
        $list=$query->order('placed_at','desc')->page($page,$size)->select()->toArray();
        foreach ($list as &$record) {
            $refundState=$this->betSubmissionRefundState($record);
            $record['lottery']=$refundState['lottery'];
            $record['open_time']=$refundState['open_time'];
            $record['can_refund']=$refundState['can_refund'];
            $record['amount']=number_format((float)$record['amount'],2,'.','');
            $record['win_amount']=number_format((float)$record['win_amount'],2,'.','');
        }
        return $this->reply(['list'=>$list,'total'=>$total,'amount_total'=>number_format($amountTotal,2,'.',''),'page'=>$page,'page_size'=>$size]);
    }

    private function submissionRecordIds(int $submissionId): array
    {
        if ($submissionId < 1) return [];
        if (!$this->betSubmissionsAvailable()) return [$submissionId];
        return Db::name('bet_records')->where('submission_id',$submissionId)->column('id');
    }

    private function betSubmissionRefundState(array $record): array
    {
        if (!$this->betSubmissionsAvailable()) {
            $details=Db::name('bet_details')->where('bet_record_id',(int)$record['id'])->column('id');
            $lotteries=$details ? array_values(array_unique(array_filter(Db::name('user_stop_drops')->whereIn('bet_detail_id',$details)->column('lottery')))) : [];
            $result=['lottery'=>implode('',array_map(static fn(string $name): string=>$name==='福彩3D'?'福':($name==='排列三'?'体':$name),$lotteries)),'open_time'=>null,'can_refund'=>false];
            if ((string)($record['status']??'')!=='pending' || !$lotteries) return $result;
            $deadlines=[];
            foreach ($lotteries as $lotteryName) {
                $control=$this->lotteryControl(['site_id'=>(int)$record['site_id'],'tenant_id'=>(int)$record['tenant_id']],$lotteryName);
                if ((int)($control['refund_enabled']??1)!==1 || $this->cutoffReached($control)) return $result;
                $lotteryId=(int)Db::name('lotteries')->where('tenant_id',(int)$record['tenant_id'])->where('name',$lotteryName)->whereNull('deleted_at')->value('id');
                if ($lotteryId<1) return $result;
                $drawn=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('code',(string)$record['issue_no'])->order('open_time','desc')->find();
                if (is_array($drawn) && !empty($drawn['open_time']) && strtotime((string)$drawn['open_time']) <= time()) return $result;
                $deadline=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('next_code',(string)$record['issue_no'])->order('open_time','desc')->value('next_open_time');
                if (!$deadline || strtotime((string)$deadline)<=time()) return $result;
                $deadlines[]=(string)$deadline;
            }
            sort($deadlines);
            $result['open_time']=$deadlines[0]??null;
            $result['can_refund']=true;
            return $result;
        }
        $recordIds=$this->submissionRecordIds((int)$record['id']);
        if ($recordIds === []) {
            return ['lottery'=>'','open_time'=>null,'can_refund'=>false];
        }
        $details=Db::name('bet_details')->whereIn('bet_record_id',$recordIds)->column('id');
        $lotteries=$details ? array_values(array_unique(array_filter(Db::name('user_stop_drops')->whereIn('bet_detail_id',$details)->column('lottery')))) : [];
        $result=['lottery'=>implode('',array_map(static fn(string $name): string=>$name==='福彩3D'?'福':($name==='排列三'?'体':$name),$lotteries)),'open_time'=>null,'can_refund'=>false];
        if ((string)($record['status']??'')!=='pending' || !$lotteries) return $result;
        $deadlines=[];
        foreach ($lotteries as $lotteryName) {
            $control=$this->lotteryControl(['site_id'=>(int)$record['site_id'],'tenant_id'=>(int)$record['tenant_id']],$lotteryName);
            if ((int)($control['refund_enabled']??1)!==1 || $this->cutoffReached($control)) return $result;
            $lotteryId=(int)Db::name('lotteries')->where('tenant_id',(int)$record['tenant_id'])->where('name',$lotteryName)->whereNull('deleted_at')->value('id');
            if ($lotteryId<1) return $result;
            // 当前期会在开奖前预先写入开奖记录；只有到了实际开奖时间才算已开奖。
            $drawn=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('code',(string)$record['issue_no'])->order('open_time','desc')->find();
            if (is_array($drawn) && !empty($drawn['open_time']) && strtotime((string)$drawn['open_time']) <= time()) return $result;
            $deadline=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('next_code',(string)$record['issue_no'])->order('open_time','desc')->value('next_open_time');
            if (!$deadline || strtotime((string)$deadline)<=time()) return $result;
            $deadlines[]=(string)$deadline;
        }
        sort($deadlines);
        $result['open_time']=$deadlines[0]??null;
        $result['can_refund']=true;
        return $result;
    }

    public function refundBetRecord(Request $request): \think\response\Json
    {
        $s=$this->session($request); $id=(int)$request->param('id');
        if (!$this->betSubmissionsAvailable()) {
            try {
                $amount=Db::transaction(function () use ($s,$id): float {
                    $record=Db::name('bet_records')->where('id',$id)->where('site_id',$s['site_id'])->where('user_id',$s['user_id'])->lock(true)->find();
                    if (!$record) throw new \InvalidArgumentException('投注记录不存在');
                    $state=$this->betSubmissionRefundState($record);
                    if (!$state['can_refund']) throw new \DomainException((string)($record['status']??'')==='refunded'?'该注单已经退回':'该期已开奖或已到开奖时间，不能退回');
                    $now=date('Y-m-d H:i:s'); $amount=(float)$record['amount'];
                    Db::name('bet_records')->where('id',$id)->update(['status'=>'refunded','sealed'=>1,'refunded_at'=>$now]);
                    Db::name('bet_details')->where('bet_record_id',$id)->update(['status'=>'refunded']);
                    (new InterceptionAllocator())->releaseForRecord($id);
                    Db::name('site_users')->where('id',$s['user_id'])->where('site_id',$s['site_id'])->update(['used_balance'=>Db::raw('GREATEST(used_balance - '.number_format($amount,2,'.','').', 0)'),'updated_at'=>$now]);
                    return $amount;
                });
            } catch (\InvalidArgumentException $e) { return $this->reply(null,$e->getMessage(),404); }
              catch (\DomainException $e) { return $this->reply(null,$e->getMessage(),409); }
              catch (\Throwable $e) { return $this->reply(null,'退单失败，请稍后重试',500); }
            return $this->reply(['record_id'=>$id,'amount'=>number_format($amount,2,'.','')],'退单成功');
        }
        try {
            $amount=Db::transaction(function () use ($s,$id): float {
                $record=Db::name('bet_submissions')->where('id',$id)->where('site_id',$s['site_id'])->where('user_id',$s['user_id'])->lock(true)->find();
                if (!$record) throw new \InvalidArgumentException('投注记录不存在');
                $recordIds=$this->submissionRecordIds((int)$record['id']);
                if ($recordIds === []) throw new \InvalidArgumentException('投注记录不存在');
                $state=$this->betSubmissionRefundState($record);
                if (!$state['can_refund']) throw new \DomainException((string)($record['status']??'')==='refunded'?'该注单已经退回':'该期已开奖或已到开奖时间，不能退回');
                $now=date('Y-m-d H:i:s'); $amount=(float)$record['amount'];
                Db::name('bet_submissions')->where('id',$id)->update(['status'=>'refunded','sealed'=>1,'refunded_at'=>$now]);
                Db::name('bet_records')->whereIn('id',$recordIds)->update(['status'=>'refunded','sealed'=>1,'refunded_at'=>$now]);
                Db::name('bet_details')->whereIn('bet_record_id',$recordIds)->update(['status'=>'refunded']);
                foreach ($recordIds as $recordId) (new InterceptionAllocator())->releaseForRecord((int)$recordId);
                Db::name('site_users')->where('id',$s['user_id'])->where('site_id',$s['site_id'])->update(['used_balance'=>Db::raw('GREATEST(used_balance - '.number_format($amount,2,'.','').', 0)'),'updated_at'=>$now]);
                return $amount;
            });
        } catch (\InvalidArgumentException $e) { return $this->reply(null,$e->getMessage(),404); }
          catch (\DomainException $e) { return $this->reply(null,$e->getMessage(),409); }
          catch (\Throwable $e) { return $this->reply(null,'退单失败，请稍后重试',500); }
        return $this->reply(['record_id'=>$id,'amount'=>number_format($amount,2,'.','')],'退单成功');
    }
    public function stopDrops(Request $request): \think\response\Json
    {
        $s=$this->session($request); [$from,$to]=$this->range($request); $query=Db::name('user_stop_drops')->where('site_id',$s['site_id'])->where('user_id',$s['user_id']);
        if ($from) $query->where('placed_at','>=',$from); if ($to) $query->where('placed_at','<=',$to);
        $number=trim((string)$request->param('number','')); if ($number!=='') $query->whereLike('number_text','%'.$number.'%');
        $type=(string)$request->param('type','all'); if (in_array($type,['stop','drop'],true)) $query->where('stop_type',$type);
        $lottery=(string)$request->param('lottery','all');
        $lotteryMap=['体'=>'排列三','福'=>'福彩3D','排列三'=>'排列三','福彩3D'=>'福彩3D'];
        if (isset($lotteryMap[$lottery])) $query->where('lottery',$lotteryMap[$lottery]);
        $category=trim((string)$request->param('category','')); if ($category!=='' && $category!=='所有') $query->where('play_type',$category);
        $total=(clone $query)->count(); $page=max(1,(int)$request->param('page',1)); $size=min(100,max(1,(int)$request->param('page_size',50))); $sort=(string)$request->param('sort','desc');
        return $this->reply(['list'=>$query->order('placed_at',$sort==='asc'?'asc':'desc')->page($page,$size)->select()->toArray(),'total'=>$total,'page'=>$page,'page_size'=>$size]);
    }
    /** @return array<int,string> */
    private function detailNumberTokens(mixed $value): array
    {
        $tokens=preg_split('/[\s,，、]+/u',trim((string)$value),-1,PREG_SPLIT_NO_EMPTY)?:[];
        return array_values(array_filter(array_map(static fn(string $token): string=>mb_substr(trim($token),0,64),$tokens),static fn(string $token): bool=>$token!==''));
    }

    private function normalizeDoubleFlyNumber(string $value): string
    {
        $value=preg_replace('/^0(?=\d{2}(?:飞)?$)/u','',$value)??$value;
        return preg_replace('/飞$/u','',$value)??$value;
    }

    private function detailPlayLabel(mixed $playType,mixed $category): string
    {
        $value=trim((string)($playType?:$category));
        if ($value==='') return '';
        if (str_contains($value,'直选')||$value==='直') return '直';
        if (str_contains($value,'组三')) return '组3';
        if (str_contains($value,'组六')) return '组6';
        if (str_contains($value,'组选')) return '组';
        return mb_substr($value,0,4);
    }

    private function detailOrderNumber(array $row): string
    {
        $explicit=trim((string)($row['submission_order_no']??$row['order_no']??''));
        if ($explicit!=='') return $explicit;
        $parent=(int)($row['submission_id']??0); if ($parent<1) $parent=(int)($row['bet_record_id']??$row['id']??0);
        $stamp=preg_replace('/\D+/','',(string)($row['placed_at']??''))??'';
        $stamp=substr($stamp,2,12); if (strlen($stamp)<12) $stamp=str_pad($stamp,12,'0');
        return $stamp.str_pad((string)($parent%100),2,'0');
    }

    private function detailMoney(float $amount): string
    {
        $value=rtrim(rtrim(number_format($amount,2,'.',''),'0'),'.');
        return $value===''?'0':$value;
    }

    /** @return array<int,string> */
    private function splitDetailMoney(float $amount,int $count): array
    {
        $count=max(1,$count); $cents=(int)round($amount*100); $base=intdiv($cents,$count); $remainder=$cents-($base*$count); $parts=[];
        for($index=0;$index<$count;$index++) $parts[]=$this->detailMoney(($base+($index<$remainder?1:0))/100);
        return $parts;
    }

    /** @param array<int,string> $tokens */
    private function isExpandedGroupPackage(array $tokens,string $source): bool
    {
        if(count($tokens)<2||preg_match('/(?<!\d)([0-9]{3,10})\s*(组三|组六)[一二两三四五六七八九1-9]码/u',$source,$match)!==1)return false;
        $selected=array_values(array_unique(str_split((string)$match[1])));$required=$match[2]==='组三'?2:3;
        foreach($tokens as $token){$digits=array_values(array_unique(str_split($token)));if(preg_match('/^\d{3}$/',$token)!==1||count($digits)!==$required||array_diff($digits,$selected)!==[])return false;}
        return true;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,string> */
    private function detailDrawMap(array $rows,int $tenantId): array
    {
        $lotteries=[];$issues=[];
        foreach($rows as $row){$lottery=trim((string)($row['lottery']??''));$issue=trim((string)($row['issue_no']??''));if($lottery!==''&&$issue!==''){$lotteries[$lottery]=true;$issues[$issue]=true;}}
        if($lotteries===[]||$issues===[])return [];
        $histories=Db::name('lottery_histories')->alias('h')->join('lotteries l','l.id=h.lottery_id')
            ->where('l.tenant_id',$tenantId)->whereIn('l.name',array_keys($lotteries))->whereIn('h.code',array_keys($issues))
            ->field('l.name AS lottery,h.code,h.one,h.two,h.three,h.numbers')->select()->toArray();
        $map=[];
        foreach($histories as $history){$draw=(string)($history['one']??'').(string)($history['two']??'').(string)($history['three']??'');if(preg_match('/^\d{3}$/',$draw)!==1)$draw=preg_replace('/\D/','',(string)($history['numbers']??''))?:'';if(preg_match('/^\d{3}$/',$draw)===1)$map[(string)$history['lottery'].'|'.(string)$history['code']]=$draw;}
        return $map;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function detailTotals(array $rows): array
    {
        $totals=['amount'=>0.0,'win_amount'=>0.0,'rebate'=>0.0,'offline_rebate'=>0.0,'profit'=>0.0];
        foreach($rows as $row) foreach($totals as $key=>$value) $totals[$key]+=(float)($row[$key]??0);
        foreach($totals as $key=>$value) $totals[$key]=$this->detailMoney($value);
        return $totals;
    }

    public function betDetails(Request $request): \think\response\Json
    {
        $s=$this->session($request); [$from,$to]=$this->range($request); $query=Db::name('bet_details')->alias('d')
            ->leftJoin('bet_records r','r.id=d.bet_record_id')->leftJoin('user_stop_drops s','s.bet_detail_id=d.id')
            ->where('d.site_id',$s['site_id'])->where('d.user_id',$s['user_id']);
        $hasSubmissions=$this->betSubmissionsAvailable(); if($hasSubmissions) $query->leftJoin('bet_submissions b','b.id=r.submission_id');
        $submissionId=(int)$request->param('submission_id',0); $recordId=(int)$request->param('bet_record_id',0);
        if($submissionId>0){$recordIds=$this->submissionRecordIds($submissionId);$query->whereIn('d.bet_record_id',$recordIds?:[-1]);}elseif($recordId>0)$query->where('d.bet_record_id',$recordId);
        if($from)$query->where('d.placed_at','>=',$from);if($to)$query->where('d.placed_at','<=',$to);
        $issue=trim((string)$request->param('issue_no',''));if($issue!=='')$query->where('d.issue_no',$issue);
        $number=trim((string)$request->param('number',''));if($number!=='')$query->whereLike('d.number_text','%'.$number.'%');
        $lottery=trim((string)$request->param('lottery',''));if($lottery!=='')$query->where('s.lottery',$lottery);
        $category=trim((string)$request->param('category',''));if($category!==''&&$category!=='所有')$query->where('s.play_type',$category);
        $metric=(string)$request->param('metric','odds');$metric=in_array($metric,['odds','amount'],true)?$metric:'odds';$min=$request->param('min');$max=$request->param('max');
        if($min!==null&&$min!==''&&is_numeric($min))$query->where('d.'.$metric,'>=',(float)$min);if($max!==null&&$max!==''&&is_numeric($max))$query->where('d.'.$metric,'<=',(float)$max);if((string)$request->param('winning','')==='1')$query->where('d.status','won');
        $sort=(string)$request->param('sort','desc')==='asc'?'asc':'desc';
        $fields='d.id,d.bet_record_id,d.issue_no,d.number_text,d.category,d.amount,d.odds,d.win_amount,d.rebate,d.status,d.placed_at,d.source_text AS detail_source,s.play_type,s.lottery,s.drop_odds,r.submission_id,r.source_text AS record_source,r.formatted_text AS record_formatted_text,r.status AS record_status';
        if($hasSubmissions)$fields.=',b.id AS submission_row_id,b.source_text AS submission_source,b.formatted_text AS submission_formatted_text';
        $detailRows=$query->field($fields)->order('d.placed_at',$sort)->order('d.id',$sort)->select()->toArray(); $expanded=[];$draws=$this->detailDrawMap($detailRows,(int)$s['tenant_id']);$matcher=new BetSettlement();
        foreach($detailRows as $row){
            $source=(string)($row['detail_source']??'');if($source==='')$source=(string)($row['record_source']??'');
            $originalSource=trim((string)($row['submission_source']??''))!==''?(string)$row['submission_source']:(string)($row['record_source']??'');
            if(trim($originalSource)==='')$originalSource=$source;
            $parsedText=trim((string)($row['submission_formatted_text']??''))!==''?(string)$row['submission_formatted_text']:(string)($row['record_formatted_text']??'');
            if(trim($parsedText)==='')$parsedText=$source;
            $storedNumberText=trim((string)($row['number_text']??''));$tokens=$this->detailNumberTokens($storedNumberText);
            $playSource=(string)($row['play_type']??'').' '.(string)($source);
            if (str_contains($playSource,'双飞') || str_contains($playSource,'对子')) $tokens=array_map([$this,'normalizeDoubleFlyNumber'],$tokens);
            $matchTokens=$tokens;
            // 复式包在数据库中继续使用 000 作为结算占位，但明细页面应显示
            // 一条真实的复式选号（例如“复024567”），不能显示 000。
            if(count($tokens)===1&&$tokens[0]==='000'&&preg_match('/(?<!\d)(\d{1,10})\s+复式[一二两三四五六七八九1-9]码/u',$source,$package))$tokens=['复'.$package[1]];
            if($tokens===[])$tokens=['-'];if($sort==='desc')$tokens=array_reverse($tokens);$count=count($tokens);
            $amounts=$this->splitDetailMoney((float)$row['amount'],$count);$wins=$this->splitDetailMoney((float)$row['win_amount'],$count);$rebates=$this->splitDetailMoney((float)$row['rebate'],$count);$offlineTotal=round((float)$row['amount']*max(0,(float)($row['drop_odds']??0)),2);$offlineRebates=$this->splitDetailMoney($offlineTotal,$count);$orderNo=$this->detailOrderNumber($row);$resolved=(float)$row['win_amount']<=0;$winningIndexes=[];
            $draw=$draws[(string)($row['lottery']??'').'|'.(string)($row['issue_no']??'')]??'';
            if((float)$row['win_amount']>0&&$draw!==''){$winningIndexes=array_keys(array_filter($matchTokens,static fn(string $token):bool=>$matcher->numberMatches($token,$draw,$source)));if($winningIndexes!==[]){$wins=array_fill(0,$count,'0');$winningParts=$this->splitDetailMoney((float)$row['win_amount'],count($winningIndexes));foreach($winningIndexes as $winningIndex=>$tokenIndex)$wins[$tokenIndex]=$winningParts[$winningIndex];$resolved=true;}}
            $groupPackage=$this->isExpandedGroupPackage($matchTokens,$source);$displayOdds=$row['odds']===null?null:(float)$row['odds']*($groupPackage?$count:1);$oddsText=$displayOdds===null?'-':rtrim(rtrim(number_format($displayOdds,3,'.',''),'0'),'.');
            foreach($tokens as $index=>$token){$amount=(float)$amounts[$index];$win=(float)$wins[$index];$rebate=(float)$rebates[$index];$offlineRebate=(float)$offlineRebates[$index];$tokenStatus=(string)($row['status']??$row['record_status']??'pending');if($resolved&&$tokenStatus==='won')$tokenStatus=$win>0?'won':'unwon';$groupFirst=$index===0;$profit=$tokenStatus==='pending'?0.0:($win-$amount+$rebate+$offlineRebate);$expanded[]=['id'=>(int)$row['id'],'row_key'=>(int)$row['id'].'-'.$index,'detail_group_id'=>(int)$row['id'],'detail_group_index'=>$index,'detail_group_size'=>$count,'group_first'=>$groupFirst,'is_group_first'=>$groupFirst,'show_text_button'=>$groupFirst,'bet_record_id'=>(int)($row['bet_record_id']??0),'submission_id'=>(int)($row['submission_id']??0)?:null,'order_no'=>$orderNo,'issue_no'=>(string)$row['issue_no'],'number_text'=>$token,'stored_number_text'=>$storedNumberText,'category'=>(string)($row['category']??''),'play_type'=>(string)($row['play_type']??''),'play_label'=>$this->detailPlayLabel($row['play_type']??'',$row['category']??''),'lottery'=>(string)($row['lottery']??''),'amount'=>$amounts[$index],'odds'=>$oddsText,'win_amount'=>$wins[$index],'is_winning_number'=>in_array($index,$winningIndexes,true),'win_projection_resolved'=>$resolved,'rebate'=>$rebates[$index],'offline_rebate'=>$this->detailMoney($offlineRebate),'profit'=>$this->detailMoney($profit),'status'=>$tokenStatus,'placed_at'=>(string)$row['placed_at'],'source_text'=>$originalSource,'original_source_text'=>$originalSource,'parsed_source_text'=>$parsedText];}
        }
        $total=count($expanded);$page=max(1,(int)$request->param('page',1));$size=min(100,max(1,(int)$request->param('page_size',40)));$pageRows=array_slice($expanded,($page-1)*$size,$size);$allTotals=$this->detailTotals($expanded);$pageTotals=$this->detailTotals($pageRows);
        return $this->reply(['list'=>$pageRows,'total'=>$total,'page'=>$page,'page_size'=>$size,'total_amount'=>$allTotals['amount'],'win_amount'=>$allTotals['win_amount'],'rebate'=>$allTotals['rebate'],'offline_rebate'=>$allTotals['offline_rebate'],'profit'=>$allTotals['profit'],'page_total'=>$pageTotals]);
    }
    public function bills(Request $request): \think\response\Json
    {
        $s=$this->session($request); $query=Db::name('bills')->where('site_id',$s['site_id'])->where('user_id',$s['user_id']);
        $from=trim((string)$request->param('from','')); $to=trim((string)$request->param('to','')); if ($from) $query->where('bill_date','>=',$from); if ($to) $query->where('bill_date','<=',$to);
        $list=$query->order('bill_date','desc')->select()->toArray();
        // Older deployments do not backfill the bills summary table. Build the
        // same daily view from the source records until a summary exists.
        $recordsQuery=Db::name('bet_records')->where('site_id',$s['site_id'])->where('user_id',$s['user_id']);
        if ($from) $recordsQuery->where('placed_at','>=',$from.' 00:00:00');
        if ($to) $recordsQuery->where('placed_at','<=',$to.' 23:59:59');
        $records=$recordsQuery->select()->toArray();
        if ($records) {
            $recordIds=array_map(static fn(array $row): int => (int)$row['id'],$records);
            $detailRows=$recordIds ? Db::name('bet_details')->alias('d')->leftJoin('user_stop_drops s','s.bet_detail_id=d.id')->whereIn('d.bet_record_id',$recordIds)->field('d.bet_record_id,d.rebate,d.amount,s.drop_odds')->select()->toArray() : [];
            $rebates=[];$offlineRebates=[];
            foreach ($detailRows as $detail) {$recordKey=(int)$detail['bet_record_id'];$rebates[$recordKey]=($rebates[$recordKey]??0)+(float)$detail['rebate'];$offlineRebates[$recordKey]=($offlineRebates[$recordKey]??0)+round((float)$detail['amount']*max(0,(float)($detail['drop_odds']??0)),2);}
            $daily=[];
            foreach ($records as $record) {
                $date=substr((string)$record['placed_at'],0,10);
                if (!isset($daily[$date])) $daily[$date]=['bill_date'=>$date,'bet_count'=>0,'amount'=>0.0,'rebate'=>0.0,'offline_rebate'=>0.0,'win_amount'=>0.0,'profit'=>0.0];
                $amount=(float)$record['amount']; $rebate=(float)($rebates[(int)$record['id']]??0); $offline=(float)($offlineRebates[(int)$record['id']]??0); $win=(float)$record['win_amount'];
                $daily[$date]['bet_count']+=(int)$record['bet_count']; $daily[$date]['amount']+=$amount; $daily[$date]['rebate']+=$rebate; $daily[$date]['offline_rebate']+=$offline; $daily[$date]['win_amount']+=$win; $daily[$date]['profit']+=($win-$amount+$rebate+$offline);
            }
            $list=array_values($daily); usort($list,static fn(array $a,array $b): int => strcmp($b['bill_date'],$a['bill_date']));
        }
        $total=['bet_count'=>0,'amount'=>'0.00','rebate'=>'0.00','offline_rebate'=>'0.00','win_amount'=>'0.00','profit'=>'0.00']; foreach ($list as $row) foreach ($total as $key=>$value) $total[$key]=$key==='bet_count' ? $total[$key]+(int)$row[$key] : number_format((float)$total[$key]+(float)$row[$key],2,'.','');
        return $this->reply(['list'=>$list,'total'=>$total]);
    }
    public function draws(Request $request): \think\response\Json
    {
        $s=$this->session($request);
        $lottery=trim((string)$request->param('lottery',''));
        if ($lottery === '') return $this->reply(['list'=>[]]);
        $this->assertLotteryPermission($s,$lottery);
        $lotteryRow=Db::name('lotteries')->alias('l')->join('site_lotteries sl','sl.lottery_id=l.id')
            ->where('sl.site_id',$s['site_id'])->where('sl.tenant_id',$s['tenant_id'])->where('l.tenant_id',$s['tenant_id'])
            ->where('l.name',$lottery)->where('l.status',1)->whereNull('l.deleted_at')->field('l.id,l.name')->find();
        if (!$lotteryRow) return $this->reply(['list'=>[]]);

        $configuredLimit=(int)Db::name('settings')->where('site_id',$s['site_id'])->where('key','draw_history_limit')->value('value');
        $defaultLimit=$configuredLimit>0?min(200,$configuredLimit):80;
        $size=min($defaultLimit,max(1,(int)$request->param('page_size',$defaultLimit)));
        $lotteryId=(int)$lotteryRow['id'];
        $control=$this->lotteryControl($s,$lottery);
        $showNext=$this->timingState($control)['show_next_issue'];
        $latestSource=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->whereNotNull('next_code')->order('open_time','desc')->order('id','desc')->find();
        $lotteryMeta=Db::name('lotteries')->where('id',$lotteryId)->find();
        if ((string)($lotteryMeta['source_type']??'official')==='system') (new SystemLotteryService())->runLottery($lotteryMeta?:[]);
        elseif ($latestSource) (new LotteryHistorySync())->ensureNextHistory($latestSource,['id'=>$lotteryId,'tenant_id'=>(int)$s['tenant_id']]);
        $histories=Db::name('lottery_histories')->where('lottery_id',$lotteryId)
            ->order('open_time','desc')->order('id','desc')->limit($size)->select()->toArray();
        $list=[];
        foreach ($histories as $history) {
            $hasNumbers=(int)($history['is_opened']??1)===1 && trim((string)($history['numbers']??''))!=='';
            if (!$showNext && !$hasNumbers) continue;
            $numbers=[];
            foreach (['one','two','three'] as $field) if ($history[$field] !== null && $history[$field] !== '') $numbers[]=(int)$history[$field];
            if (count($numbers)<3) $numbers=array_map('intval',array_slice(preg_split('/[,，\s]+/u',trim((string)($history['numbers']??'')),-1,PREG_SPLIT_NO_EMPTY)?:[],0,3));
            $complete=count($numbers)===3;
            $sum=$complete?array_sum($numbers):null;
            $opened=(int)($history['is_opened']??1)===1;
            $list[]=['lottery'=>$lotteryRow['name'],'issue_no'=>(string)$history['code'],'draw_date'=>(string)($history['draw_day']??''),'draw_time'=>$history['open_time']??null,'numbers'=>$opened&&$complete?implode(',',$numbers):'','sum_value'=>$opened?$sum:null,'size'=>$opened&&$complete?($sum>=14?'大':'小'):null,'parity'=>$opened&&$complete?($sum%2===0?'双':'单'):null,'span_value'=>$opened&&$complete?max($numbers)-min($numbers):null,'pending'=>$opened?0:1];
        }
        return $this->reply(['list'=>$list]);
    }

    public function waitDraws(Request $request): \think\response\Json
    {
        $s=$this->session($request); $lottery=trim((string)$request->param('lottery',''));
        if ($lottery==='') return $this->reply(['changed'=>false]);
        $this->assertLotteryPermission($s,$lottery);
        $lotteryId=(int)Db::name('lotteries')->alias('l')->join('site_lotteries sl','sl.lottery_id=l.id')
            ->where('sl.site_id',$s['site_id'])->where('sl.tenant_id',$s['tenant_id'])->where('l.name',$lottery)
            ->where('l.status',1)->whereNull('l.deleted_at')->value('l.id');
        if ($lotteryId<1) return $this->reply(['changed'=>false]);
        $since=trim((string)$request->param('since','')); $signature='';
        for ($attempt=0; $attempt<16; $attempt++) {
            $latest=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->order('open_time','desc')->order('id','desc')->field('id,code,numbers,updated_at')->find();
            $signature=$latest ? implode('|',[(string)$latest['id'],(string)$latest['code'],(string)$latest['numbers'],(string)$latest['updated_at']]) : '';
            if ($signature!=='' && $signature!==$since) return $this->reply(['changed'=>true,'signature'=>$signature]);
            if ($attempt<15) usleep(500000);
        }
        return $this->reply(['changed'=>false,'signature'=>$signature]);
    }

    private function quickLines(string $text, string $lottery, int $tenantId=1): array
    {
        $unitStake=(float)Db::name('lotteries')->where('tenant_id',$tenantId)->where('name',$lottery)->where('status',1)->whereNull('deleted_at')->value('unit_stake');
        return (new QuickEntryParser())->parse($text, $lottery, $unitStake>0?$unitStake:2.0);
    }
    /** @return array<int,string> */
    private function lotteriesForLine(array $line,string $fallback): array
    {
        return match((string)($line['category']??'')){'福'=>['福彩3D'],'体'=>['排列三'],'福体'=>['福彩3D','排列三'],default=>[$fallback]};
    }
    private function lineForLottery(array $line,string $lottery,int $tenantId): array
    {
        $raw=trim((string)($line['parse_text']??$line['raw_text']??''));
        if($raw==='')throw new \InvalidArgumentException('投注行缺少原始文本，无法按彩种计算金额');
        $signature=(string)($line['play_type']??'').'|'.(string)($line['number_text']??'');
        $matches=[];
        foreach($this->quickLines($raw,$lottery,$tenantId) as $candidate){
            if(($candidate['status']??'')!=='success')continue;
            $candidateSignature=(string)($candidate['play_type']??'').'|'.(string)($candidate['number_text']??'');
            if($candidateSignature===$signature)$matches[]=$candidate;
        }
        if(count($matches)!==1)throw new \InvalidArgumentException('投注行无法按'.$lottery.'的单注金额唯一重算，已禁止下注');
        $line=$matches[0];
        $lotteries=$this->lotteriesForLine($line,$lottery);
        $parts=count($lotteries);
        if ($parts>1) {
            $count=(int)($line['count']??0);
            if ($count<1 || $count%$parts!==0) throw new \InvalidArgumentException('福体投注注数无法按彩种拆分');
            $line['count']=intdiv($count,$parts);
            if (isset($line['stake_count'])) {
                $stakeCount=(int)$line['stake_count'];
                if ($stakeCount<1 || $stakeCount%$parts!==0) throw new \InvalidArgumentException('福体投注金额注数无法按彩种拆分');
                $line['stake_count']=intdiv($stakeCount,$parts);
            }
            if (isset($line['code_count'])) {
                $codeCount=(int)$line['code_count'];
                if ($codeCount<1 || $codeCount%$parts!==0) throw new \InvalidArgumentException('福体投注码数无法按彩种拆分');
                $line['code_count']=intdiv($codeCount,$parts);
            }
            $line['amount']=number_format((float)($line['amount']??0)/$parts,2,'.','');
        }
        // 福彩/体彩是兼容旧快录语法的“跨彩种”标记。普通系统彩保留
        // 解析出的彩种标记，避免被误改成“体”后再次路由到排列三。
        $category=(string)($line['category']??'');
        if ($lottery==='福彩3D') $category='福';
        elseif ($lottery==='排列三') $category='体';
        elseif ($category==='' || $category==='福体') $category=$lottery;
        $line['category']=$category;
        if (isset($line['settlement_text'])) $line['settlement_text']=str_replace('福体',$category,(string)$line['settlement_text']);
        return $line;
    }
    private function submissionFingerprint(array $session,array $groups): string
    {
        ksort($groups);$payload=[];
        foreach($groups as $lottery=>$group){$lines=[];foreach($group['lines'] as $entry){$line=$entry['line'];$selection=(string)($line['settlement_text']??$line['parse_text']??$line['raw_text']??'');$lines[]=['play'=>(string)($line['play_type']??''),'numbers'=>(string)($line['number_text']??''),'selection'=>$selection,'requested'=>number_format((float)$entry['rule']['requested'],2,'.',''),'actual'=>number_format((float)$entry['rule']['actual'],2,'.','')];}$payload[]=['lottery'=>$lottery,'issue'=>(string)$group['issue_no'],'lines'=>$lines];}
        return hash('sha256',json_encode(['site'=>(int)$session['site_id'],'user'=>(int)$session['user_id'],'bets'=>$payload],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    private function recentDuplicateRecords(array $session,string $fingerprint): array
    {
        if (!$this->betSubmissionsAvailable()) {
            return Db::name('bet_records')->where('site_id',$session['site_id'])->where('user_id',$session['user_id'])->where('submission_fingerprint',$fingerprint)->where('created_at','>=',date('Y-m-d H:i:s',time()-15))->order('id')->select()->toArray();
        }
        return Db::name('bet_submissions')->where('site_id',$session['site_id'])->where('user_id',$session['user_id'])->where('submission_fingerprint',$fingerprint)->where('created_at','>=',date('Y-m-d H:i:s',time()-15))->order('id')->select()->toArray();
    }
    private function assertLotteryPermission(array $session, string $lottery, bool $bet=false): void
    {
        $lotteryId=(int)Db::name('lotteries')->alias('l')->join('site_lotteries sl','sl.lottery_id=l.id')
            ->where('sl.site_id',$session['site_id'])->where('sl.tenant_id',$session['tenant_id'])->where('l.tenant_id',$session['tenant_id'])
            ->where('l.name',$lottery)->where('l.status',1)->whereNull('l.deleted_at')->value('l.id');
        if ($lotteryId < 1) throw new \InvalidArgumentException('当前站点未开通该彩种');
        $hasPermissions=Db::name('user_lottery_permissions')->where('site_id',$session['site_id'])->where('user_id',$session['user_id'])->count()>0;
        if (!$hasPermissions) return;
        $permission=Db::name('user_lottery_permissions')->where('site_id',$session['site_id'])->where('user_id',$session['user_id'])->where('lottery_id',$lotteryId)->find();
        if (!$permission || (int)$permission['can_view']!==1) throw new \InvalidArgumentException('您没有该彩种的访问权限');
        if ($bet && (int)$permission['can_bet']!==1) throw new \InvalidArgumentException('您没有该彩种的下注权限');
    }
    public function quickPreview(Request $request): \think\response\Json
    {
        $s=$this->session($request); $text=trim((string)$request->post('text','')); if (mb_strlen($text)>10000) return $this->reply(null,'投注文本不能超过10000个字符',422); $lottery=trim((string)$request->post('lottery','福彩3D')); if ($lottery==='') return $this->reply(null,'彩种无效',422);
        try { $this->assertLotteryPermission($s,$lottery); } catch (\InvalidArgumentException $e) { return $this->reply(null,$e->getMessage(),422); }
        if ($text==='') return $this->reply(['lines'=>[],'count'=>0,'amount'=>'0.00'],'请输入投注文本',422);
        $lines=$this->quickLines($text,$lottery,(int)$s['tenant_id']); $count=0; $codeCount=0; $amount=0.0;
        foreach ($lines as &$line) if ($line['status']==='success') {
            $lineLotteries=$this->lotteriesForLine($line,$lottery);$oddsReady=true;
            $lineAmount=0.0;$lineCount=0;
            $lineCodeCount=(int)($line['code_count']??$line['count']??0);
            try{foreach($lineLotteries as $lineLottery){$this->assertLotteryPermission($s,$lineLottery);$splitLine=$this->lineForLottery($line,$lineLottery,(int)$s['tenant_id']);if(!$this->lineOdds($s,$lineLottery,$splitLine))$oddsReady=false;$lineAmount+=(float)$splitLine['amount'];$lineCount+=(int)$splitLine['count'];}}
            catch(\InvalidArgumentException $e){$oddsReady=false;$line['reason']=$e->getMessage();}
            if (!$oddsReady) { $line['status']='failed'; $line['reason']=$line['reason']??'当前玩法无法唯一匹配赔率'; $line['amount']='0.00'; $line['count']=0; continue; }
            $line['amount']=number_format($lineAmount,2,'.','');$line['count']=$lineCount;$line['code_count']=$lineCodeCount;if(isset($line['ast']))$line['ast']['amount']=$lineAmount;
            $count+=$lineCount; $codeCount+=$lineCodeCount; $amount+=$lineAmount;
        }
        unset($line);
        $batchStats=[];
        foreach($lines as $line){
            $batchId=(string)($line['batch_id']??'');if($batchId==='')continue;
            if(!isset($batchStats[$batchId]))$batchStats[$batchId]=['valid'=>true,'rows'=>0,'expected'=>(int)($line['batch_size']??0),'numbers'=>[],'seen'=>[],'occurrences'=>[],'frequency'=>[],'category_multiplier'=>1,'stake_count'=>0,'amount'=>0.0];
            $batchStats[$batchId]['rows']++;$batchStats[$batchId]['expected']=max((int)$batchStats[$batchId]['expected'],(int)($line['batch_size']??0));
            if(($line['status']??'')!=='success'){$batchStats[$batchId]['valid']=false;continue;}
            $numbers=preg_split('/\s+/',trim((string)($line['number_text']??'')))?:[];
            foreach($numbers as $number){if(preg_match('/^\d{3}$/',$number)!==1)continue;$key='n'.$number;$batchStats[$batchId]['occurrences'][]=$number;$batchStats[$batchId]['frequency'][$key]=($batchStats[$batchId]['frequency'][$key]??0)+1;if(!isset($batchStats[$batchId]['seen'][$key])){$batchStats[$batchId]['seen'][$key]=true;$batchStats[$batchId]['numbers'][]=$number;}}
            $batchStats[$batchId]['category_multiplier']=max((int)$batchStats[$batchId]['category_multiplier'],count($this->lotteriesForLine($line,$lottery)));$batchStats[$batchId]['stake_count']+=(int)($line['stake_count']??$line['count']??0);$batchStats[$batchId]['amount']+=(float)($line['amount']??0);
        }
        foreach($lines as &$line){
            $batchId=(string)($line['batch_id']??'');if($batchId===''||($line['batch_end']??false)!==true)continue;$stats=$batchStats[$batchId]??null;
            $valid=is_array($stats)&&($stats['valid']??false)===true&&(int)($stats['rows']??0)===(int)($stats['expected']??-1);$line['batch_valid']=$valid;
            if(!$valid){unset($line['batch_count'],$line['batch_stake_count'],$line['batch_amount'],$line['batch_number_text'],$line['batch_occurrence_text'],$line['batch_has_duplicates'],$line['batch_duplicate_numbers']);continue;}
            $duplicates=[];foreach($stats['frequency'] as $key=>$frequency)if((int)$frequency>1)$duplicates[]=substr((string)$key,1);
            $line['batch_count']=count($stats['numbers'])*(int)$stats['category_multiplier'];$line['batch_stake_count']=(int)$stats['stake_count'];$line['batch_amount']=number_format((float)$stats['amount'],2,'.','');$line['batch_number_text']=implode(' ',$stats['numbers']);$line['batch_occurrence_text']=implode(' ',$stats['occurrences']);$line['batch_has_duplicates']=$duplicates!==[];$line['batch_duplicate_numbers']=$duplicates;
        }
        unset($line);
        $count=0;$codeCount=0;$amount=0.0;
        foreach($lines as $line){
            $batchId=(string)($line['batch_id']??'');
            if($batchId!==''){if(($line['batch_end']??false)===true&&($line['batch_valid']??false)===true){$batchCount=(int)($line['batch_count']??0);$count+=$batchCount;$codeCount+=$batchCount;$amount+=(float)($line['batch_amount']??0);}continue;}
            if(($line['status']??'')!=='success')continue;$lineCount=(int)($line['count']??0);$count+=$lineCount;$codeCount+=(int)($line['code_count']??$lineCount);$amount+=(float)($line['amount']??0);
        }
        return $this->reply(['lines'=>$lines,'count'=>$count,'code_count'=>$codeCount,'amount'=>number_format($amount,2,'.',''),'formatted_text'=>(new QuickEntryParser())->formatText($text)]);
    }
    public function quickPlace(Request $request): \think\response\Json
    {
        $s=$this->session($request); if (!(bool)$request->post('confirmed',false)) return $this->reply(null,'请确认下注内容后再提交',422);
        if ((string)Db::name('site_users')->where('id',$s['user_id'])->where('site_id',$s['site_id'])->value('account_state')==='bet_paused') return $this->reply(null,'当前账号已暂停下注',403);
        $text=trim((string)$request->post('text','')); if ($text==='' || mb_strlen($text)>10000) return $this->reply(null,'投注文本无效',422); $lottery=trim((string)$request->post('lottery','福彩3D')); if ($lottery==='') return $this->reply(null,'彩种无效',422);
        try { $this->assertLotteryPermission($s,$lottery,true); } catch (\InvalidArgumentException $e) { return $this->reply(null,$e->getMessage(),422); }
        $parser=new QuickEntryParser(); $formattedText=$parser->formatText($text);
        $lines=$this->quickLines($text,$lottery,(int)$s['tenant_id']);
        if (!$lines) return $this->reply(null,'没有可下注的有效内容',422);
        foreach ($lines as $line) if (($line['status']??'')!=='success') return $this->reply(null,'存在未识别或金额不一致的内容，已取消整单下注',422);
        $now=date('Y-m-d H:i:s'); $amount=0.0; $count=0; $groups=[];
        try {
            foreach ($lines as $line) foreach ($this->lotteriesForLine($line,$lottery) as $lineLottery) {
                $this->assertLotteryPermission($s,$lineLottery,true);
                $splitLine=$this->lineForLottery($line,$lineLottery,(int)$s['tenant_id']);
                $rule=$this->applyLineLimits($s,$lineLottery,$splitLine);
                $groups[$lineLottery]['lines'][]=['line'=>$splitLine,'rule'=>$rule];
                $groups[$lineLottery]['amount']=($groups[$lineLottery]['amount']??0)+(float)$rule['actual'];
                $groups[$lineLottery]['count']=($groups[$lineLottery]['count']??0)+(int)$splitLine['count'];
                $amount+=(float)$rule['actual']; $count+=(int)$splitLine['count'];
            }
            foreach ($groups as $lineLottery=>&$group) {
                $control=$this->lotteryControl($s,$lineLottery);
                if (!$this->timingAllowsBet($control)) throw new \InvalidArgumentException($lineLottery.'当前时段禁止下注');
                $lotteryId=(int)Db::name('lotteries')->where('tenant_id',$s['tenant_id'])->where('name',$lineLottery)->where('status',1)->whereNull('deleted_at')->value('id');
                if ($lotteryId<1) throw new \InvalidArgumentException('当前彩种不存在或已停用');
                $nextHistory=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->whereNotNull('next_code')->where('next_code','<>','')->order('open_time desc')->order('id desc')->find();
                $pendingHistory=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('is_opened',0)->where('open_time','>=',$now)->order('open_time asc')->order('id asc')->find();
                $closingTime=$nextHistory['next_open_time']??($pendingHistory['open_time']??null);
                if ($this->cutoffReached($control) || (!$this->cutoffConfigured($control) && $closingTime && strtotime((string)$closingTime)<=time())) throw new \InvalidArgumentException($lineLottery.'已封盘，整单未下注');
                $issueNo=(string)($nextHistory['next_code']??($pendingHistory['code']??''));
                if ($issueNo==='') throw new \InvalidArgumentException($lineLottery.'暂无可下注期号，整单未下注');
                $group['lottery_id']=$lotteryId; $group['issue_no']=$issueNo;
            }
            unset($group);
        } catch (\InvalidArgumentException $e) { return $this->reply(null,$e->getMessage(),422); }
        $submissionFingerprint=$this->submissionFingerprint($s,$groups);
        if ($amount<=0) return $this->reply(null,'当前投注已全部停押，暂不能下注',422);
        $user=Db::name('site_users')->where('id',$s['user_id'])->where('site_id',$s['site_id'])->whereNull('deleted_at')->field('balance,credit_balance,used_balance')->find();
        if (!$user) return $this->reply(null,'用户不存在或已停用',404);
        $available=(float)$user['balance']+(float)$user['credit_balance']-(float)$user['used_balance']; if ($amount>$available) return $this->reply(null,'可用余额不足，无法下注',422);
        if (!$this->betSubmissionsAvailable()) {
            try {
                $transactionResult=Db::transaction(function () use ($s,$text,$formattedText,$groups,$amount,$now,$submissionFingerprint): array {
                    $lockedUser=Db::name('site_users')->where('id',(int)$s['user_id'])->where('site_id',(int)$s['site_id'])->lock(true)->find();
                    if (!$lockedUser) throw new \RuntimeException('用户不存在或已停用');
                    $duplicates=$this->recentDuplicateRecords($s,$submissionFingerprint);
                    if($duplicates!==[]){return ['duplicate'=>true,'record_ids'=>array_map(static fn(array $row):int=>(int)$row['id'],$duplicates),'count'=>array_sum(array_map(static fn(array $row):int=>(int)$row['bet_count'],$duplicates)),'amount'=>array_sum(array_map(static fn(array $row):float=>(float)$row['amount'],$duplicates))];}
                    $before=(float)$lockedUser['balance']+(float)$lockedUser['credit_balance']-(float)$lockedUser['used_balance'];
                    if ($amount>$before) throw new \RuntimeException('可用余额不足，无法下注');
                    $recordIds=[]; $ledgerBefore=$before;
                    foreach ($groups as $lineLottery=>$group) {
                        $issueNo=(string)$group['issue_no']; $recordAmount=(float)$group['amount']; $recordCount=(int)$group['count']; $lotteryId=(int)$group['lottery_id'];
                        $recordId=(int)Db::name('bet_records')->insertGetId(['tenant_id'=>$s['tenant_id'],'site_id'=>$s['site_id'],'user_id'=>$s['user_id'],'issue_no'=>$issueNo,'source_text'=>$text,'formatted_text'=>$formattedText,'submission_fingerprint'=>$submissionFingerprint,'bet_count'=>$recordCount,'amount'=>$recordAmount,'win_amount'=>0,'status'=>'pending','sealed'=>0,'placed_at'=>$now,'created_at'=>$now]);
                        $recordIds[]=$recordId;
                        foreach ($group['lines'] as $entry) {
                        $line=$entry['line']; $rule=$entry['rule'];
                        $settlementText=(string)($line['settlement_text']??$line['raw_text']);
                        $detailId=(int)Db::name('bet_details')->insertGetId(['tenant_id'=>$s['tenant_id'],'site_id'=>$s['site_id'],'user_id'=>$s['user_id'],'bet_record_id'=>$recordId,'issue_no'=>$issueNo,'number_text'=>$line['number_text'],'category'=>$line['category'],'amount'=>number_format($rule['actual'],2,'.',''),'odds'=>$rule['actual_odds']===null?null:number_format($rule['actual_odds'],4,'.',''),'win_amount'=>0,'rebate'=>0,'status'=>'pending','placed_at'=>$now,'source_text'=>$settlementText]);
                        preg_match('/直|组三|组六|组|胆|拖|跨|和|单双|大小|飞|定位|复式|豹子/u',$settlementText,$playMatch); $playType=(string)($line['play_type']??($playMatch[0] ?? ''));
                        Db::name('user_stop_drops')->insert(['tenant_id'=>$s['tenant_id'],'site_id'=>$s['site_id'],'user_id'=>$s['user_id'],'bet_detail_id'=>$detailId,'lottery'=>$lineLottery,'issue_no'=>$issueNo,'number_text'=>$line['number_text'],'play_type'=>$playType,'stop_type'=>$rule['stop_type'],'original_amount'=>number_format($rule['requested'],2,'.',''),'actual_amount'=>number_format($rule['actual'],2,'.',''),'stop_amount'=>number_format($rule['stop_amount'],2,'.',''),'original_odds'=>$rule['original_odds']===null?null:number_format($rule['original_odds'],4,'.',''),'actual_odds'=>$rule['actual_odds']===null?null:number_format($rule['actual_odds'],4,'.',''),'drop_odds'=>number_format($rule['drop_odds'],4,'.',''),'source_text'=>$settlementText,'placed_at'=>$now,'created_at'=>$now]);
                        (new InterceptionAllocator())->allocate(['tenant_id'=>$s['tenant_id'],'site_id'=>$s['site_id'],'user_id'=>$s['user_id'],'lottery_id'=>$lotteryId,'issue_no'=>$issueNo,'bet_record_id'=>$recordId,'bet_detail_id'=>$detailId,'number_text'=>$line['number_text'],'amount'=>$rule['actual'],'odds'=>$rule['odds_row']]);
                        }
                        CreditLedger::userBet($s,(int)$s['user_id'],$recordAmount,$ledgerBefore,$recordId,$issueNo);
                        $ledgerBefore-=$recordAmount;
                    }
                    Db::name('site_users')->where('id',$s['user_id'])->where('site_id',$s['site_id'])->update(['used_balance'=>Db::raw('used_balance + '.number_format($amount,2,'.',''))]);
                    return ['duplicate'=>false,'record_ids'=>$recordIds];
                });
            } catch (\Throwable $e) {
                Log::error('quickPlace failed: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
                $origin=(string)$request->header('origin');
                $localTest=preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i',$origin)===1;
                return $this->reply(null,$localTest ? '下注保存失败：'.$e->getMessage() : '下注保存失败，请稍后重试',500);
            }
            $recordIds=$transactionResult['record_ids'];
            if(($transactionResult['duplicate']??false)===true)return $this->reply(['record_id'=>(int)$recordIds[0],'record_ids'=>$recordIds,'count'=>(int)$transactionResult['count'],'amount'=>number_format((float)$transactionResult['amount'],2,'.','')],'请勿重复提交',409);
            return $this->reply(['record_id'=>(int)$recordIds[0],'record_ids'=>$recordIds,'count'=>$count,'amount'=>number_format($amount,2,'.',''),'formatted_text'=>$formattedText],'下注提交成功');
        }
        try {
            $transactionResult=Db::transaction(function () use ($s,$text,$formattedText,$groups,$amount,$count,$now,$submissionFingerprint): array {
                $lockedUser=Db::name('site_users')->where('id',(int)$s['user_id'])->where('site_id',(int)$s['site_id'])->lock(true)->find();
                if (!$lockedUser) throw new \RuntimeException('用户不存在或已停用');
                $duplicates=$this->recentDuplicateRecords($s,$submissionFingerprint);
                if($duplicates!==[]){return ['duplicate'=>true,'record_ids'=>array_map(static fn(array $row):int=>(int)$row['id'],$duplicates),'count'=>array_sum(array_map(static fn(array $row):int=>(int)$row['bet_count'],$duplicates)),'amount'=>array_sum(array_map(static fn(array $row):float=>(float)$row['amount'],$duplicates))];}
                $before=(float)$lockedUser['balance']+(float)$lockedUser['credit_balance']-(float)$lockedUser['used_balance'];
                if ($amount>$before) throw new \RuntimeException('可用余额不足，无法下注');
                $submissionId=(int)Db::name('bet_submissions')->insertGetId([
                    'tenant_id'=>$s['tenant_id'],
                    'site_id'=>$s['site_id'],
                    'user_id'=>$s['user_id'],
                    'issue_no'=>array_key_first($groups) ? (string)($groups[array_key_first($groups)]['issue_no'] ?? '') : '',
                    'source_text'=>$text,
                    'formatted_text'=>$formattedText,
                    'submission_fingerprint'=>$submissionFingerprint,
                    'bet_count'=>$count,
                    'amount'=>number_format($amount,2,'.',''),
                    'win_amount'=>'0.00',
                    'status'=>'pending',
                    'sealed'=>0,
                    'placed_at'=>$now,
                    'created_at'=>$now,
                ]);
                $recordIds=[]; $ledgerBefore=$before;
                foreach ($groups as $lineLottery=>$group) {
                    $issueNo=(string)$group['issue_no']; $recordAmount=(float)$group['amount']; $recordCount=(int)$group['count']; $lotteryId=(int)$group['lottery_id'];
                    $recordId=(int)Db::name('bet_records')->insertGetId(['submission_id'=>$submissionId,'tenant_id'=>$s['tenant_id'],'site_id'=>$s['site_id'],'user_id'=>$s['user_id'],'issue_no'=>$issueNo,'source_text'=>$text,'formatted_text'=>$formattedText,'submission_fingerprint'=>$submissionFingerprint,'bet_count'=>$recordCount,'amount'=>$recordAmount,'win_amount'=>0,'status'=>'pending','sealed'=>0,'placed_at'=>$now,'created_at'=>$now]);
                    $recordIds[]=$recordId;
                    foreach ($group['lines'] as $entry) {
                    $line=$entry['line']; $rule=$entry['rule'];
                    $settlementText=(string)($line['settlement_text']??$line['raw_text']);
                    $detailId=(int)Db::name('bet_details')->insertGetId(['tenant_id'=>$s['tenant_id'],'site_id'=>$s['site_id'],'user_id'=>$s['user_id'],'bet_record_id'=>$recordId,'issue_no'=>$issueNo,'number_text'=>$line['number_text'],'category'=>$line['category'],'amount'=>number_format($rule['actual'],2,'.',''),'odds'=>$rule['actual_odds']===null?null:number_format($rule['actual_odds'],4,'.',''),'win_amount'=>0,'rebate'=>0,'status'=>'pending','placed_at'=>$now,'source_text'=>$settlementText]);
                    preg_match('/直|组三|组六|组|胆|拖|跨|和|单双|大小|飞|定位|复式|豹子/u',$settlementText,$playMatch); $playType=(string)($line['play_type']??($playMatch[0] ?? ''));
                    Db::name('user_stop_drops')->insert(['tenant_id'=>$s['tenant_id'],'site_id'=>$s['site_id'],'user_id'=>$s['user_id'],'bet_detail_id'=>$detailId,'lottery'=>$lineLottery,'issue_no'=>$issueNo,'number_text'=>$line['number_text'],'play_type'=>$playType,'stop_type'=>$rule['stop_type'],'original_amount'=>number_format($rule['requested'],2,'.',''),'actual_amount'=>number_format($rule['actual'],2,'.',''),'stop_amount'=>number_format($rule['stop_amount'],2,'.',''),'original_odds'=>$rule['original_odds']===null?null:number_format($rule['original_odds'],4,'.',''),'actual_odds'=>$rule['actual_odds']===null?null:number_format($rule['actual_odds'],4,'.',''),'drop_odds'=>number_format($rule['drop_odds'],4,'.',''),'source_text'=>$settlementText,'placed_at'=>$now,'created_at'=>$now]);
                    (new InterceptionAllocator())->allocate(['tenant_id'=>$s['tenant_id'],'site_id'=>$s['site_id'],'user_id'=>$s['user_id'],'lottery_id'=>$lotteryId,'issue_no'=>$issueNo,'bet_record_id'=>$recordId,'bet_detail_id'=>$detailId,'number_text'=>$line['number_text'],'amount'=>$rule['actual'],'odds'=>$rule['odds_row']]);
                    }
                    CreditLedger::userBet($s,(int)$s['user_id'],$recordAmount,$ledgerBefore,$submissionId,$issueNo);
                    $ledgerBefore-=$recordAmount;
                }
                Db::name('site_users')->where('id',$s['user_id'])->where('site_id',$s['site_id'])->update(['used_balance'=>Db::raw('used_balance + '.number_format($amount,2,'.',''))]);
                return ['duplicate'=>false,'record_ids'=>$recordIds,'submission_id'=>$submissionId];
            });
        } catch (\Throwable $e) {
            Log::error('quickPlace failed: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
            $origin=(string)$request->header('origin');
            $localTest=preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i',$origin)===1;
            return $this->reply(null,$localTest ? '下注保存失败：'.$e->getMessage() : '下注保存失败，请稍后重试',500);
        }
        $recordIds=$transactionResult['record_ids'];
        $submissionId=(int)($transactionResult['submission_id'] ?? ($recordIds[0] ?? 0));
        if(($transactionResult['duplicate']??false)===true)return $this->reply(['record_id'=>$submissionId,'record_ids'=>$recordIds,'count'=>(int)$transactionResult['count'],'amount'=>number_format((float)$transactionResult['amount'],2,'.','')],'请勿重复提交',409);
        return $this->reply(['record_id'=>$submissionId,'record_ids'=>$recordIds,'count'=>$count,'amount'=>number_format($amount,2,'.',''),'formatted_text'=>$formattedText],'下注提交成功');
    }
    public function quickSettings(Request $request): \think\response\Json
    {
        $s=$this->session($request); $row=Db::name('user_quick_preferences')->where('site_id',$s['site_id'])->where('user_id',$s['user_id'])->find(); $tags=Db::name('user_quick_tags')->where('site_id',$s['site_id'])->where('user_id',$s['user_id'])->order('id')->field('id,name')->select()->toArray();
        return $this->reply(['preferences'=>$row ? (json_decode((string)$row['preferences'],true) ?: []) : ['autoBet'=>true,'recognize'=>false,'copyTicket'=>false,'copyHeader'=>false,'textMode'=>false,'lottery'=>'福彩3D'],'tags'=>$tags]);
    }
    public function saveQuickSettings(Request $request): \think\response\Json
    {
        $s=$this->session($request); $preferences=$request->post('preferences',[]); if (!is_array($preferences)) return $this->reply(null,'偏好设置格式错误',422); $now=date('Y-m-d H:i:s'); $existing=Db::name('user_quick_preferences')->where('site_id',$s['site_id'])->where('user_id',$s['user_id'])->find(); $data=['tenant_id'=>$s['tenant_id'],'site_id'=>$s['site_id'],'user_id'=>$s['user_id'],'preferences'=>json_encode($preferences,JSON_UNESCAPED_UNICODE),'updated_at'=>$now]; if ($existing) Db::name('user_quick_preferences')->where('id',$existing['id'])->update($data); else Db::name('user_quick_preferences')->insert($data); return $this->reply(['preferences'=>$preferences],'设置已保存');
    }
    public function createQuickTag(Request $request): \think\response\Json
    {
        $s=$this->session($request); $name=trim((string)$request->post('name','')); if ($name==='' || mb_strlen($name)>40) return $this->reply(null,'标签名称无效',422); try { $id=Db::name('user_quick_tags')->insertGetId(['tenant_id'=>$s['tenant_id'],'site_id'=>$s['site_id'],'user_id'=>$s['user_id'],'name'=>$name,'created_at'=>date('Y-m-d H:i:s')]); } catch (\Throwable $e) { return $this->reply(null,'标签已存在',409); } return $this->reply(['id'=>$id,'name'=>$name],'标签已添加');
    }
    public function deleteQuickTag(Request $request): \think\response\Json
    {
        $s=$this->session($request); $id=(int)$request->param('id'); Db::name('user_quick_tags')->where('id',$id)->where('site_id',$s['site_id'])->where('user_id',$s['user_id'])->delete(); return $this->reply(null,'标签已删除');
    }
}
