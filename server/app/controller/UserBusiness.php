<?php
declare(strict_types=1);
namespace app\controller;

use app\service\InterceptionAllocator;
use app\service\CreditLedger;
use app\service\BetSettlement;
use app\service\LotteryHistorySync;
use app\service\SystemLotteryService;
use app\service\ThirdPartyQuickEntryClient;
use app\service\ThirdPartyQuickEntryConfig;
use app\service\ThirdPartyQuickEntryUtils;
use app\service\DailyScoreUsage;
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
        // Preserve the protected scheduler marker. quickPlace uses it to
        // distinguish a historical catch-up request from a normal member
        // request; dropping it forces every robot bet onto the wall clock.
        return [
            'tenant_id'=>$tenantId,
            'site_id'=>$siteId,
            'user_id'=>$userId,
            'robot_scheduler'=>(bool)($session['robot_scheduler']??false),
        ];
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
        foreach((array)($control['timing_rules']??[]) as $rule){ if(!is_array($rule)) continue; [$sh,$sm]=array_map('intval',explode(':',(string)($rule['start_time']??'00:00'))); [$eh,$em]=array_map('intval',explode(':',(string)($rule['end_time']??'23:59'))); $start=$sh*60+$sm; $end=$eh*60+$em; $in=$start<=$end?($minutes>=$start&&$minutes<=$end):($minutes>=$start||$minutes<=$end); if($in){$matched=$rule;break;} }
        if($matched!==null)return ['allow_bet'=>(int)($matched['allow_bet']??1)===1,'mask_enabled'=>(int)($matched['mask_enabled']??0)===1,'show_next_issue'=>(int)($matched['show_next_issue']??1)===1,'display_text'=>(string)($matched['display_text']??'')];
        return ['allow_bet'=>true,'mask_enabled'=>(int)($control['mask_enabled']??1)!==0,'show_next_issue'=>true,'display_text'=>''];
    }
    private function timingAllowsBet(array $control): bool { return $this->timingState($control)['allow_bet']; }
    private function lineOdds(array $session, string $lottery, array $line): array
    {
        $boardCode=trim((string)($line['board_code']??'A')); if ($boardCode==='') $boardCode='A';
        $lotteryRow=Db::name('lotteries')->alias('l')->join('site_lotteries sl','sl.lottery_id=l.id')
            ->where('sl.site_id',$session['site_id'])->where('sl.tenant_id',$session['tenant_id'])
            ->where('l.tenant_id',$session['tenant_id'])->where('l.name',$lottery)->where('l.status',1)->whereNull('l.deleted_at')
            ->order('l.source_type asc')->order('l.id asc')->field('l.id')->find();
        if (!$lotteryRow) return [];
        $lotteryId=(int)$lotteryRow['id'];
        $source=(string)($line['settlement_text']??$line['raw_text']??'');
        $matched=(new \app\service\BetSettlement())->oddsRowFor($lotteryId,$source);
        if (!$matched) return [];
        $matched['platform_single_item_limit']=$matched['single_item_limit']??0;
        // Member odds may have been saved against another active record with
        // the same display lottery name (for example a system copy versus the
        // official copy). Resolve the newest matching override by logical
        // lottery/category/play identity instead of only the selected ID.
        $overrides=[];
        $directId=(int)$matched['id']>=1000000000;
        $candidateLotteries=Db::name('lotteries')->where('tenant_id',$session['tenant_id'])->where('name',$lottery)->whereNull('deleted_at')->column('id');
        foreach ($candidateLotteries as $candidateLotteryId) {
            $candidateIds=[];
            if ($directId) {
                $categoryId=(int)$candidateLotteryId>0 ? (int)Db::name('lottery_odds_categories')->where('lottery_id',(int)$candidateLotteryId)->where('board_code',$boardCode)->where('name',(string)$matched['name'])->whereNull('deleted_at')->value('id') : 0;
                if ($categoryId>0) $candidateIds[] = 1000000000+$categoryId;
            } else {
                $candidateIds=Db::name('lottery_odds')->where('lottery_id',(int)$candidateLotteryId)->where('board_code',$boardCode)->where('category',(string)$matched['category'])->where('name',(string)$matched['name'])->whereNull('deleted_at')->column('id');
            }
            foreach ($candidateIds as $candidateId) {
                $candidate=Db::name('user_lottery_odds')->where('site_id',$session['site_id'])->where('user_id',$session['user_id'])->where('lottery_odds_id',(int)$candidateId)->where('board_code',$boardCode)->find();
                if ($candidate) $overrides[]=$candidate;
            }
        }
        usort($overrides,static fn(array $a,array $b): int=>strcmp((string)($b['updated_at']??''),(string)($a['updated_at']??'')));
        $override=$overrides[0]??null;
        if ($override) foreach (['min_bet','odds_limit','single_bet_limit','single_item_limit','odds','offline_rebate'] as $field) if (array_key_exists($field,$override)) $matched[$field]=$override[$field];
        return $matched;
    }
    private function applyLineLimits(array $session, string $lottery, array $line): array
    {
        $requested=(float)($line['amount']??0); $count=max(1,(int)($line['stake_count']??$line['count']??1)); $odds=$this->lineOdds($session,$lottery,$line);
        if (!$odds) {
            $play=trim((string)($line['play_type']??$line['category']??''));
            $source=trim((string)($line['settlement_text']??$line['raw_text']??''));
            // Make an unsupported provider catalogue row actionable. In
            // particular, “复式12” is recognized as 复式二码 but the odds
            // catalogue intentionally starts at 复式三码; it must not make
            // the whole mixed ticket fail with an opaque generic message.
            if (preg_match('/^复式(?:二|2)码$/u',$play)===1 || preg_match('/(?<!\d)(?:复式|复试)\s*12(?:\D|$)/u',$source)===1) {
                throw new \InvalidArgumentException('复式二码暂未配置赔率，复式请使用三码至九码；如要下注12，请改写为“组三两码”');
            }
            throw new \InvalidArgumentException(($play!==''?'玩法“'.$play.'”':'当前玩法').'无法匹配已配置赔率，请检查玩法名称或联系管理员配置赔率');
        }
        $actual=$requested;
        $minimum=(float)($odds['min_bet']??0);$perNumber=$requested/$count;
        if($minimum>0&&$perNumber+0.000001<$minimum)throw new \InvalidArgumentException('每个号码最小下注金额为 '.rtrim(rtrim(number_format($minimum,2,'.',''),'0'),'.'));
        $singleBet=(float)($odds['single_bet_limit']??0); $singleItem=(float)($odds['single_item_limit']??0);
        // Limits apply to each generated number/stake in this line. A
        // provider catalogue row may contain hundreds of different direct
        // selections, so its total amount must scale with the reported
        // stake_count instead of being capped at one fixed 1500-yuan value.
        // Reject the complete line when its per-number amount exceeds either
        // configured limit; never silently truncate the user's requested
        // amount and leave the preview/order totals inconsistent.
        if ($singleBet>0 && $requested > $singleBet*$count + 0.000001) {
            throw new \InvalidArgumentException('玩法“'.(string)($line['play_type']??$line['category']??'当前玩法').'”超过单注上限：最多 '.number_format($singleBet*$count,2,'.','').' 元，当前输入 '.number_format($requested,2,'.','').' 元，请修改后再提交');
        }
        if ($singleItem>0 && $requested > $singleItem*$count + 0.000001) {
            throw new \InvalidArgumentException('玩法“'.(string)($line['play_type']??$line['category']??'当前玩法').'”超过单项上限：最多 '.number_format($singleItem*$count,2,'.','').' 元，当前输入 '.number_format($requested,2,'.','').' 元，请修改后再提交');
        }
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
                // Use the id selected by lotteryControl().  A tenant may have
                // both an official and a system lottery with the same display
                // name; resolving by name alone can select the wrong history.
                $lotteryId=(int)($control['id']??0);
                if ($lotteryId<1) $lotteryId=(int)Db::name('lotteries')->where('tenant_id',(int)$record['tenant_id'])->where('name',$lotteryName)->whereNull('deleted_at')->order('id','asc')->value('id');
                if ($lotteryId<1) return $result;
                $historyLotteryIds=Db::name('lotteries')->where('tenant_id',(int)$record['tenant_id'])->where('name',$lotteryName)->whereNull('deleted_at')->column('id');
                if ($historyLotteryIds===[]) $historyLotteryIds=[$lotteryId];
                $current=Db::name('lottery_histories')->whereIn('lottery_id',$historyLotteryIds)->where('code',(string)$record['issue_no'])->order('open_time','desc')->find();
                if (is_array($current)) {
                    if ((int)($current['is_opened']??1)===1) return $result;
                    $deadline=$current['open_time']??null;
                } else {
                    $deadline=Db::name('lottery_histories')->whereIn('lottery_id',$historyLotteryIds)->where('next_code',(string)$record['issue_no'])->order('open_time','desc')->value('next_open_time');
                }
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
            // Keep the lottery/control/history rows aligned when duplicate
            // lottery names exist in a tenant (official + system variants).
            $lotteryId=(int)($control['id']??0);
            if ($lotteryId<1) $lotteryId=(int)Db::name('lotteries')->where('tenant_id',(int)$record['tenant_id'])->where('name',$lotteryName)->whereNull('deleted_at')->order('id','asc')->value('id');
            if ($lotteryId<1) return $result;
            $historyLotteryIds=Db::name('lotteries')->where('tenant_id',(int)$record['tenant_id'])->where('name',$lotteryName)->whereNull('deleted_at')->column('id');
            if ($historyLotteryIds===[]) $historyLotteryIds=[$lotteryId];
            // 当前期会在开奖前预先写入开奖记录；只有到了实际开奖时间才算已开奖。
            // 福体提交的外层 issue_no 只取第一彩种；从 stop-drop 明细
            // 读取每个彩种自己的期号，避免福彩3D/排列三期号格式不同。
            $issueNo=(string)($record['issue_no']??'');
            $detailIssue=Db::name('user_stop_drops')->whereIn('bet_detail_id',$details)->where('lottery',$lotteryName)->order('id','asc')->value('issue_no');
            if ($detailIssue!==null && trim((string)$detailIssue)!=='') $issueNo=(string)$detailIssue;
            $current=Db::name('lottery_histories')->whereIn('lottery_id',$historyLotteryIds)->where('code',$issueNo)->order('open_time','desc')->find();
            if (is_array($current)) {
                if ((int)($current['is_opened']??1)===1) return $result;
                $deadline=$current['open_time']??null;
            } else {
                $deadline=Db::name('lottery_histories')->whereIn('lottery_id',$historyLotteryIds)->where('next_code',$issueNo)->order('open_time','desc')->value('next_open_time');
            }
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
                    DailyScoreUsage::changeForPlacedAt((int)$s['user_id'], -$amount, (string)($record['placed_at'] ?? $record['created_at'] ?? ''));
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
                DailyScoreUsage::changeForPlacedAt((int)$s['user_id'], -$amount, (string)($record['placed_at'] ?? $record['created_at'] ?? ''));
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
        $rawTokens=preg_split('/[\s,，、]+/u',trim((string)$value),-1,PREG_SPLIT_NO_EMPTY)?:[];
        $tokens=[];
        for($index=0,$total=count($rawTokens);$index<$total;$index++){
            $token=trim((string)$rawTokens[$index]);
            if(($token==='三'||$token==='六') && $index+1<$total && preg_match('/^\d{2,10}$/u',(string)$rawTokens[$index+1])===1)$token.=(string)$rawTokens[++$index];
            if($token!=='')$tokens[]=mb_substr($token,0,64);
        }
        return $tokens;
    }

    private function normalizeDoubleFlyNumber(string $value): string
    {
        $value=preg_replace('/^0(?=\d{2}(?:飞)?$)/u','',$value)??$value;
        return preg_replace('/飞$/u','',$value)??$value;
    }
    private function detailPlayLabel(mixed $playType,mixed $category,mixed $source=''): string
    {
        $value=trim((string)($playType?:$category));
        $sourceText=(string)$source;
        if ($value==='') return '';
        if (str_contains($value,'组三')&&str_contains($value,'全包')) return '组三全包';
        if (str_contains($value,'组六')&&str_contains($value,'全包')) return '组六全包';
        if (str_contains($value,'豹子')&&str_contains($value,'全包')) return '豹子全包';
        if (str_contains($value,'对子')&&str_contains($value,'全包')) return '对子全包';
        // 胆拖行的内部玩法名是“1码拖N”，具体的组三/组六只保存在
        // 该明细的结算文本中。优先读取明细级标记，避免一张同时包含
        // 组三和组六的原始文本把两行合并成同一个标题。
        if (preg_match('/(?:^|\s)(组三|组六)胆拖(?:\s|$)/u',$sourceText,$dragFamily) === 1) {
            return $dragFamily[1].'胆拖';
        }
        // “1胆2345678组六组三各1倍”会按两条多码结算行保存，胆码
        // 只保留在原始文本。详情按每行实际的组三/组六玩法恢复标题。
        if (preg_match('/(?<!\d)\d{1,2}\s*胆\s*\d{2,9}(?!\d)/u',$sourceText) === 1) {
            if (str_contains($value,'组六')) return '组六胆拖';
            if (str_contains($value,'组三')) return '组三胆拖';
        }
        // 沾边赖明细的行级玩法通常保存为“组六赖五码/组三赖五码”，
        // 而“沾边赖”只出现在主单原文中。详情表头要保留这个业务语义，
        // 不能落回普通的组6/组3，否则用户无法区分玩法。
        if (preg_match('/(?:沾边赖|粘边赖|赖)/u', $sourceText) === 1) {
            if (str_contains($value,'组六')) return '组六沾边赖';
            if (str_contains($value,'组三')) return '组三沾边赖';
        }
        // 定位玩法在明细中会被展开成三位号码，但其来源仍保留百/十/个
        // 三个位置。优先按原始文本恢复定位级别，避免误标为直选。
        $locatorCount=0;
        foreach (['百','十','个'] as $locatorPosition) {
            if (preg_match('/'.$locatorPosition.'位?\s*[0-9０-９]/u',$sourceText)===1) $locatorCount++;
        }
        if ($locatorCount>0) return [1=>'一码定位',2=>'二码定位',3=>'三码定位'][$locatorCount]??$locatorCount.'码定位';
        if (str_contains($value,'直选')||$value==='直') return '直';
        if ($value==='胆') return '独胆';
        if (str_contains($value,'组三')||str_contains($value,'组六')||str_contains($value,'组选')) {
            // The compiler may split a generic “一组/组选” input into
            // 组三/组六 rows for odds and settlement. Only expose 组3/组6
            // when the original text explicitly named that subtype.
            if (str_contains($value,'组三') && str_contains($sourceText,'组三')) return '组3';
            if (str_contains($value,'组六') && str_contains($sourceText,'组六')) return '组6';
            if (!str_contains($value,'组三') && !str_contains($value,'组六')) {
                if (str_contains($sourceText,'组三') && !str_contains($sourceText,'组六')) return '组3';
                if (str_contains($sourceText,'组六') && !str_contains($sourceText,'组三')) return '组6';
            }
            return '组选';
        }
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

    /** Collapse legacy expanded detail rows back to the provider's catalogue
     * selection. For example, `123 组三三码` is one 10-yuan package, not six
     * separate 1.67-yuan numbers (`112 113 221 223 331 332`).
     *
     * @param array<int,string> $tokens
     */
    private function compactGroupPackageForDetail(array $row,array $tokens): ?string
    {
        if(count($tokens)<2)return null;
        $playType=trim((string)($row['play_type']??''));
        if(preg_match('/^(组三|组六)([一二两三四五六七八九])码$/u',$playType,$play)!==1)return null;
        $lengths=['一'=>1,'二'=>2,'两'=>2,'三'=>3,'四'=>4,'五'=>5,'六'=>6,'七'=>7,'八'=>8,'九'=>9];
        $selectionLength=$lengths[$play[2]]??0;
        if($selectionLength<2)return null;
        $requiredUnique=$play[1]==='组三'?2:3;
        $tokenDigits=[];
        foreach($tokens as $token){
            if(preg_match('/^\d{3}$/',$token)!==1)return null;
            $digits=array_values(array_unique(str_split($token)));
            if(count($digits)!==$requiredUnique)return null;
            foreach($digits as $digit)$tokenDigits[$digit]=true;
        }
        if(count($tokenDigits)!==$selectionLength)return null;
        $recordSource=(string)($row['record_source']??'');
        if($recordSource==='')return null;
        $matchCount=preg_match_all('/(?<!\d)(\d{'.$selectionLength.'})(?!\d)/u',$recordSource,$matches);
        if($matchCount===false||$matchCount<1)return null;
        foreach((array)($matches[1]??[]) as $candidate){
            $selected=array_values(array_unique(str_split((string)$candidate)));
            if(count($selected)!==$selectionLength)continue;
            if(array_diff($selected,array_keys($tokenDigits))===[]&&array_diff(array_keys($tokenDigits),$selected)===[]){
                return ($play[1]==='组三'?'三':'六').$candidate;
            }
        }
        return null;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,string> */
    /**
     * A single three-digit group selection such as “123 组三 100” is one
     * displayed bet. The parser expands 组三三码 into its six settlement
     * combinations internally, but the detail view must keep the original
     * selection together (the play type/odds still come from the expanded
     * catalog play).
     * @param array<int,string> $tokens
     * @return array<int,string>
     */
    private function collapseSingleGroupSelection(array $tokens,string $source,string $playType): array
    {
        if (count($tokens)<=1) return $tokens;
        // Multi-code 组三/组六 is stored by some provider responses as a
        // semantic prefix plus a digit selection (for example `三 23456`),
        // while the source keeps the authoritative `23456组三五码` wording.
        // It is one selection and must remain one detail row.
        if (preg_match('/(?<!\d)(\d{4,10})\s*(组三|组六|组3|组6)(?:[一二两三四五六七八九]|[2-9])?码?/u',$source,$multi)===1) {
            return [($multi[2]==='组六'||$multi[2]==='组6'?'六':'三').$multi[1]];
        }
        // “五组” is the legacy shorthand for a three-digit 组三三码
        // selection; include it when locating the original code so the
        // detail view collapses its six permutations back to one selection.
        $beforePlay=(string)(preg_split('/(?:组三|组六|组选|五组|组)/u',$source,2)[0]??'');
        preg_match_all('/(?<!\d)\d{3}(?!\d)/u',$beforePlay,$selections);
        if (count($selections[0]??[])!==1) return $tokens;
        $selection=(string)$selections[0][0];
        $prefix=str_contains($playType,'组六')?'六':(str_contains($playType,'组三')?'三':'');
        return [$prefix.$selection];
    }
    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function normalizeLegacyDirectRows(array $rows): array
    {
        $leopardsByRecord=[];
        foreach ($rows as $row) {
            $source=(string)($row['detail_source']??$row['source_text']??'');
            if (!str_contains($source,'豹子') || str_contains($source,'豹子全包')) continue;
            $key=(string)($row['bet_record_id']??'');
            foreach ($this->detailNumberTokens($row['number_text']??'') as $token) {
                if (preg_match('/^(\d{3})直$/u',$token,$m)===1) $leopardsByRecord[$key][]=$m[1];
            }
        }
        $out=[];
        foreach ($rows as $row) {
            $source=(string)($row['detail_source']??$row['source_text']??'');
            $key=(string)($row['bet_record_id']??'');
            $isLeopardRow=str_contains($source,'豹子') && !str_contains($source,'豹子全包');
            if ($isLeopardRow && $row['odds']!==null) $row['odds']='800.0000';
            $tokens=$this->detailNumberTokens($row['number_text']??'');
            $direct=(string)($row['play_type']??'')==='直' && count($tokens)>0 && count(array_filter($tokens,static fn(string $t): bool=>preg_match('/^\d{3}直$/u',$t)===1))===count($tokens);
            if (!$direct || $isLeopardRow) { $out[]=$row; continue; }
            $leopardSet=array_fill_keys($leopardsByRecord[$key]??[],true);
            $normal=[];$leopard=[];
            foreach($tokens as $token){$number=substr($token,0,3);if(count(array_unique(str_split($number)))===1 && (isset($leopardSet[$number]) || $leopardSet===[]))$leopard[]=$token;else $normal[]=$token;}
            if ($leopard===[]) {$out[]=$row;continue;}
            $per=(float)($row['amount']??0)/max(1,count($tokens));
            // If a dedicated 豹子 detail already exists for this record,
            // only remove those codes from the legacy generic row; do not
            // synthesize a second leopard row.
            if ($leopardSet!==[]) {
                if ($normal!==[]) {$row['number_text']=implode(' ',$normal);$row['amount']=number_format($per*count($normal),2,'.','');$out[]=$row;}
                continue;
            }
            if ($normal!==[]) {$row['number_text']=implode(' ',$normal);$row['amount']=number_format($per*count($normal),2,'.','');$out[]=$row;}
            $leopardRow=$row;$leopardRow['number_text']=implode(' ',$leopard);$leopardRow['amount']=number_format($per*count($leopard),2,'.','');$leopardRow['detail_source']=implode(' ',array_map(static fn(string $t):string=>substr($t,0,3),$leopard)).' 直豹子各'.number_format($per,2,'.','').'元 '.(string)($row['category']??'福');$leopardRow['source_text']=$leopardRow['detail_source'];$leopardRow['odds']='800.0000';$out[]=$leopardRow;
        }
        return $out;
    }
    /** @param array<int,array<string,mixed>> $details @return array<int,array<string,mixed>> */
    private function collapseMixedDirectGroupDetails(array $details): array
    {
        $buckets=[];
        foreach($details as $index=>$detail){
            $source=(string)($detail['source_text']??'');
            if(preg_match('/[一二两三四五六七八九十\d]+\s*(?:单|直)\s*[一二两三四五六七八九十\d]+\s*组/u',$source)!==1)continue;
            $key=$source.'|'.(string)($detail['lottery']??'').'|'.(string)($detail['issue_no']??'');
            $buckets[$key][]=$index;
        }
        $remove=[];
        foreach($buckets as $indexes){
            $direct=[];$group=[];
            foreach($indexes as $index){
                $detail=$details[$index];
                $number=preg_replace('/\D+/u','',(string)($detail['number_text']??''))??'';
                if(strlen($number)!==3)continue;
                $play=(string)($detail['play_type']??'');
                if($play==='直')$direct[$number]=$index;
                elseif(in_array($play,['组','组选','组三','组六','豹子'],true))$group[$number]=$index;
            }
            foreach($direct as $number=>$directIndex){
                // Only 豹子 (all three digits equal) is collapsed. Ordinary
                // 组三/组六 numbers must remain in their own 组选 section.
                if(count(array_unique(str_split((string)$number)))!==1)continue;
                if(!isset($group[$number]))continue;
                $groupIndex=$group[$number];$directDetail=&$details[$directIndex];$groupDetail=$details[$groupIndex];
                foreach(['amount','win_amount','rebate'] as $field){
                    $directDetail[$field]=$this->detailMoney((float)($directDetail[$field]??0)+(float)($groupDetail[$field]??0));
                }
                $directDetail['profit']=$this->detailMoney((float)($directDetail['win_amount']??0)-(float)($directDetail['amount']??0)+(float)($directDetail['rebate']??0));
                $directDetail['is_winning_number']=(bool)($directDetail['is_winning_number']??false)||(bool)($groupDetail['is_winning_number']??false);
                if((string)($groupDetail['status']??'')==='won')$directDetail['status']='won';
                // A concrete three-of-a-kind uses the effective 800 odds in
                // the compact mixed-ticket display, even for legacy rows
                // whose locked catalog value was the package quote 80.
                $directDetail['odds']='800';
                unset($directDetail);
                $remove[$groupIndex]=true;
            }
        }
        if($remove===[])return $details;
        foreach(array_keys($remove) as $index)unset($details[$index]);
        return array_values($details);
    }
    /** @param array<int,array<string,mixed>> $details @return array<int,array<string,mixed>> */
    private function mergeEquivalentGroupDetails(array $details): array
    {
        $buckets=[];
        foreach($details as $index=>$detail){
            $play=(string)($detail['play_type']??'');
            if(!in_array($play,['组','组选','组三','组六','豹子'],true))continue;
            $number=preg_replace('/\D+/u','',(string)($detail['number_text']??''))??'';
            if(strlen($number)!==3||count(array_unique(str_split($number)))===1)continue;
            $digits=str_split($number);sort($digits);$canonical=implode('',$digits);
            // 只合并同一张注单内由排列组合产生的等价组选号码。
            // 不得跨 bet_record_id 合并，否则一张已退码注单会与当前注单
            // 叠加成虚假的金额（例如 2 元 + 2 元显示成 4 元）。
            $key=(string)($detail['bet_record_id']??'').'|'.(string)($detail['source_text']??'').'|'.(string)($detail['lottery']??'').'|'.(string)($detail['issue_no']??'').'|'.$play.'|'.$canonical;
            $buckets[$key][]=[$index,$canonical];
        }
        $remove=[];
        foreach($buckets as $items){
            if(count($items)<2)continue;
            [$firstIndex,$canonical]=$items[0];$first=&$details[$firstIndex];
            $suffix=str_contains((string)($first['number_text']??''),'组')?'组':'';
            $first['number_text']=$canonical.$suffix;$first['stored_number_text']=$canonical.$suffix;$first['display_number_text']=$canonical;
            foreach(array_slice($items,1) as [$index]){
                $duplicate=$details[$index];
                foreach(['amount','win_amount','rebate'] as $field)$first[$field]=$this->detailMoney((float)($first[$field]??0)+(float)($duplicate[$field]??0));
                $first['profit']=$this->detailMoney((float)($first['win_amount']??0)-(float)($first['amount']??0)+(float)($first['rebate']??0));
                $first['is_winning_number']=(bool)($first['is_winning_number']??false)||(bool)($duplicate['is_winning_number']??false);
                if((string)($duplicate['status']??'')==='won')$first['status']='won';
                $remove[$index]=true;
            }
            unset($first);
        }
        if($remove===[])return $details;
        foreach(array_keys($remove) as $index)unset($details[$index]);
        return array_values($details);
    }
    /**
     * A package expanded into multiple stored details can become the same
     * visible selection after compacting (e.g. two 0.5 rows -> `三42365`).
     * Merge only exact duplicates within one record/issue/category/play;
     * distinct plays and selections remain separate.
     * @param array<int,array<string,mixed>> $details
     * @return array<int,array<string,mixed>>
     */
    private function mergeDuplicatePackagedDetails(array $details): array
    {
        $buckets=[];
        foreach($details as $index=>$detail){
            $play=trim((string)($detail['play_type']??''));
            if(preg_match('/^(?:组三|组六)[一二两三四五六七八九1-9]码$/u',$play)!==1)continue;
            $number=preg_replace('/\s+/u','',trim((string)($detail['number_text']??'')))??'';
            if(preg_match('/^[三六]\d{2,10}$/u',$number)!==1)continue;
            $key=implode('|',[(string)($detail['bet_record_id']??0),(string)($detail['issue_no']??''),(string)($detail['category']??''),$play,$number]);
            $buckets[$key][]=$index;
        }
        $remove=[];
        foreach($buckets as $indexes){
            if(count($indexes)<2)continue;
            $firstIndex=$indexes[0];$first=&$details[$firstIndex];
            foreach(array_slice($indexes,1) as $index){
                $duplicate=$details[$index];
                foreach(['amount','win_amount','rebate','offline_rebate','profit'] as $field)$first[$field]=$this->detailMoney((float)($first[$field]??0)+(float)($duplicate[$field]??0));
                $first['is_winning_number']=(bool)($first['is_winning_number']??false)||(bool)($duplicate['is_winning_number']??false);
                if(($duplicate['status']??'')==='won')$first['status']='won';
                if(($duplicate['win_projection_resolved']??false)===true)$first['win_projection_resolved']=true;
                $remove[$index]=true;
            }
            $first['detail_group_index']=0;$first['detail_group_size']=1;$first['group_first']=true;$first['is_group_first']=true;$first['show_text_button']=true;
            unset($first);
        }
        if($remove===[])return $details;
        foreach(array_keys($remove) as $index)unset($details[$index]);
        return array_values($details);
    }
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
        $fields='d.id,d.bet_record_id,d.issue_no,d.number_text,d.category,d.amount,d.odds,d.win_amount,d.rebate,d.status,d.placed_at,d.board_code,d.source_text AS detail_source,s.play_type,s.lottery,s.drop_odds,r.submission_id,r.source_text AS record_source,r.formatted_text AS record_formatted_text,r.status AS record_status';
        if($hasSubmissions)$fields.=',b.id AS submission_row_id,b.source_text AS submission_source,b.formatted_text AS submission_formatted_text';
        $detailRows=$query->field($fields)->order('d.placed_at',$sort)->order('d.id',$sort)->select()->toArray();
        $detailRows=$this->normalizeLegacyDirectRows($detailRows);
        $expanded=[];$draws=$this->detailDrawMap($detailRows,(int)$s['tenant_id']);$matcher=new BetSettlement();
        foreach($detailRows as $row){
            $recordSource=(string)($row['record_source']??'');
            $source=(string)($row['detail_source']??'');if($source==='')$source=$recordSource;
            $originalSource=(string)($row['submission_source']??'');if($originalSource==='')$originalSource=$recordSource;if($originalSource==='')$originalSource=$source;
            $parsedText=trim((string)($row['submission_formatted_text']??''))!==''?(string)$row['submission_formatted_text']:(string)($row['record_formatted_text']??'');if(trim($parsedText)==='')$parsedText=$source;
            $storedNumberText=trim((string)($row['number_text']??''));$tokens=$this->detailNumberTokens($storedNumberText);
            $playSource=(string)($row['play_type']??'').' '.(string)($source);
            if(str_contains($playSource,'双飞')||str_contains($playSource,'对子'))$tokens=array_map([$this,'normalizeDoubleFlyNumber'],$tokens);
            $tokens=$this->collapseSingleGroupSelection($tokens,$originalSource,(string)($row['play_type']??''));
            $matchTokens=$tokens;
            // Full-package wording lives on the parent submission while the
            // detail row may contain parser atoms such as `三 3`. Keep one
            // provider row and expose the package label instead of leaking
            // those internal atoms into the detail table.
            $packageLabel='';
            if(preg_match('/(?:组三|组3)\s*(?:全包|包)/u',$recordSource)===1)$packageLabel='组三全包';
            elseif(preg_match('/(?:组六|组6)\s*(?:全包|包)/u',$recordSource)===1)$packageLabel='组六全包';
            if($packageLabel!==''){$tokens=[$packageLabel];$matchTokens=[];}
            $compactGroupPackage=$this->compactGroupPackageForDetail($row,$tokens);
            if($compactGroupPackage!==null){$tokens=[$compactGroupPackage];$matchTokens=$tokens;}
            // 复式包在数据库中继续使用 000 作为结算占位，但明细页面应显示
            // 一条真实的复式选号（例如“复024567”），不能显示 000。
            if(count($tokens)===1&&$tokens[0]==='000'&&preg_match('/(?<!\d)(\d{1,10})\s*(?:复式|复试)[一二两三四五六七八九1-9]码/u',$source,$package))$tokens=['复'.$package[1]];
            if($tokens===[])$tokens=['-'];
            // Keep hit indexes aligned with the visual order. The previous
            // code reversed only `$tokens`, so a hit such as X54 was rendered
            // on another row (for example X39) in descending views.
            if($sort==='desc'){ $tokens=array_reverse($tokens); $matchTokens=array_reverse($matchTokens); }
            $count=count($tokens);
            $amounts=$this->splitDetailMoney((float)$row['amount'],$count);$wins=$this->splitDetailMoney((float)$row['win_amount'],$count);$rebates=$this->splitDetailMoney((float)$row['rebate'],$count);$offlineTotal=round((float)$row['amount']*max(0,(float)($row['drop_odds']??0)),2);$offlineRebates=$this->splitDetailMoney($offlineTotal,$count);$orderNo=$this->detailOrderNumber($row);$resolved=(float)$row['win_amount']<=0;$winningIndexes=[];
            $draw=$draws[(string)($row['lottery']??'').'|'.(string)($row['issue_no']??'')]??'';
            if((float)$row['win_amount']>0&&$draw!==''){$winningIndexes=array_keys(array_filter($matchTokens,static fn(string $token):bool=>$matcher->numberMatches($token,$draw,$source)));if($winningIndexes!==[]){$wins=array_fill(0,$count,'0');$winningParts=$this->splitDetailMoney((float)$row['win_amount'],count($winningIndexes));foreach($winningIndexes as $winningIndex=>$tokenIndex)$wins[$tokenIndex]=$winningParts[$winningIndex];$resolved=true;}}
            $groupPackage=$this->isExpandedGroupPackage($matchTokens,$source);$displayOddsBase=$row['odds']===null?null:(float)$row['odds'];
            // Resolve the quote from this detail row's own play type. Some
            // legacy rows keep only the number in detail_source while the
            // authoritative “复式九码/组六九码/组三九码” label is in
            // play_type; never let the parent sentence containing multiple
            // plays decide the row's odds.
            $rowPlayType=trim((string)($row['play_type']??''));
            $lookupSource=$packageLabel!==''?$packageLabel:trim($source.' '.$rowPlayType);
            if($lookupSource==='')$lookupSource=$rowPlayType;
            $currentOdds=$this->lineOdds($s,(string)($row['lottery']??''),['settlement_text'=>$lookupSource,'board_code'=>(string)($row['board_code']??'A')]);if($currentOdds!==[]&&array_key_exists('odds',$currentOdds)&&is_numeric($currentOdds['odds']))$displayOddsBase=(float)$currentOdds['odds'];$displayOdds=$displayOddsBase===null?null:$displayOddsBase*($groupPackage?$count:1);$oddsText=$displayOdds===null?'-':rtrim(rtrim(number_format($displayOdds,3,'.',''),'0'),'.');
            foreach($tokens as $index=>$token){$amount=(float)$amounts[$index];$win=(float)$wins[$index];$rebate=(float)$rebates[$index];$offlineRebate=(float)$offlineRebates[$index];$tokenStatus=(string)($row['status']??$row['record_status']??'pending');if($resolved&&$tokenStatus==='won')$tokenStatus=$win>0?'won':'unwon';$groupFirst=$index===0;$expanded[]=['id'=>(int)$row['id'],'row_key'=>(int)$row['id'].'-'.$index,'detail_group_id'=>(int)$row['id'],'detail_group_index'=>$index,'detail_group_size'=>$count,'group_first'=>$groupFirst,'is_group_first'=>$groupFirst,'show_text_button'=>$groupFirst,'bet_record_id'=>(int)($row['bet_record_id']??0),'submission_id'=>(int)($row['submission_id']??0)?:null,'order_no'=>$orderNo,'issue_no'=>(string)$row['issue_no'],'number_text'=>$token,'stored_number_text'=>$storedNumberText,'category'=>(string)($row['category']??''),'play_type'=>$packageLabel!==''?$packageLabel:(string)($row['play_type']??''),'play_label'=>$packageLabel!==''?$packageLabel:$this->detailPlayLabel($row['play_type']??'',$row['category']??'',$originalSource.' '.$source),'lottery'=>(string)($row['lottery']??''),'amount'=>$amounts[$index],'odds'=>$oddsText,'win_amount'=>$wins[$index],'is_winning_number'=>in_array($index,$winningIndexes,true),'win_projection_resolved'=>$resolved,'rebate'=>$rebates[$index],'offline_rebate'=>$this->detailMoney($offlineRebate),'profit'=>$this->detailMoney($win-$amount+$rebate+$offlineRebate),'status'=>$tokenStatus,'placed_at'=>(string)$row['placed_at'],'source_text'=>$originalSource,'record_source'=>$recordSource,'original_source_text'=>$originalSource,'parsed_source_text'=>$parsedText];}
        }
        $expanded=$this->collapseMixedDirectGroupDetails($expanded);
        // Collapse equivalent permutations from legacy/provider rows only
        // after all token amounts and winning indexes have been assigned.
        $expanded=$this->mergeEquivalentGroupDetails($expanded);
        $expanded=$this->mergeDuplicatePackagedDetails($expanded);
        $total=count($expanded);$page=max(1,(int)$request->param('page',1));
        $requestedPageSize=trim((string)$request->param('page_size','40'));
        // Detail consumers that request 100 rows (or explicitly use `all`)
        // need the complete expanded ticket, not an arbitrary 100-row slice.
        // Keep the normal UI default paginated at 40 rows.
        $allRequested=strtolower($requestedPageSize)==='all' || (is_numeric($requestedPageSize) && (int)$requestedPageSize>=100);
        $size=$allRequested?max(1,$total):min(100,max(1,(int)$requestedPageSize));
        if ($allRequested) $page=1;
        $pageRows=array_slice($expanded,($page-1)*$size,$size);$allTotals=$this->detailTotals($expanded);$pageTotals=$this->detailTotals($pageRows);
        return $this->reply(['list'=>$pageRows,'total'=>$total,'page'=>$page,'page_size'=>$size,'total_amount'=>$allTotals['amount'],'win_amount'=>$allTotals['win_amount'],'rebate'=>$allTotals['rebate'],'offline_rebate'=>$allTotals['offline_rebate'],'profit'=>$allTotals['profit'],'page_total'=>$pageTotals]);
    }
    public function bills(Request $request): \think\response\Json
    {
        $s=$this->session($request); $from=trim((string)$request->param('from','')); $to=trim((string)$request->param('to',''));
        $lotteryParam=trim((string)$request->param('lottery',''));
        // user_stop_drops stores the display lottery names used by the user
        // APIs (not the short 福/体 aliases shown in some controls).
        $lotteryMap=['福'=>'福彩3D','福彩3D'=>'福彩3D','体'=>'排列三','排列三'=>'排列三','all'=>''];
        $lotteryFilter=$lotteryParam===''?'':($lotteryMap[$lotteryParam]??'__none__');
        if ($lotteryFilter==='__none__') return $this->reply(['list'=>[],'total'=>['bet_count'=>0,'amount'=>'0.00','rebate'=>'0.00','offline_rebate'=>'0.00','win_amount'=>'0.00','profit'=>'0.00']]);
        $query=Db::name('bills')->where('site_id',$s['site_id'])->where('user_id',$s['user_id']);
        if ($from) $query->where('bill_date','>=',$from); if ($to) $query->where('bill_date','<=',$to);
        // bills is a daily site/user summary and has no lottery column. When
        // a彩种 is selected, rebuild the daily result from lottery-tagged
        // detail rows instead of returning the unfiltered all-lottery total.
        $list=[];
        if ($lotteryFilter!=='') {
            $detailQuery=Db::name('bet_details')->alias('d')->leftJoin('user_stop_drops s','s.bet_detail_id=d.id')
                ->where('d.site_id',$s['site_id'])->where('d.user_id',$s['user_id'])->where('s.lottery',$lotteryFilter);
            if ($from) $detailQuery->where('d.placed_at','>=',$from.' 00:00:00');
            if ($to) $detailQuery->where('d.placed_at','<=',$to.' 23:59:59');
            $detailRows=$detailQuery->field('d.placed_at,d.amount,d.rebate,d.win_amount,s.drop_odds')->select()->toArray();
            $daily=[];
            foreach ($detailRows as $detail) {
                $date=substr((string)($detail['placed_at']??''),0,10); if ($date==='') continue;
                if (!isset($daily[$date])) $daily[$date]=['bill_date'=>$date,'bet_count'=>0,'amount'=>0.0,'rebate'=>0.0,'offline_rebate'=>0.0,'win_amount'=>0.0,'profit'=>0.0];
                $amount=(float)($detail['amount']??0); $rebate=(float)($detail['rebate']??0); $offline=round($amount*max(0,(float)($detail['drop_odds']??0)),2); $win=(float)($detail['win_amount']??0);
                $daily[$date]['bet_count']++; $daily[$date]['amount']+=$amount; $daily[$date]['rebate']+=$rebate; $daily[$date]['offline_rebate']+=$offline; $daily[$date]['win_amount']+=$win; $daily[$date]['profit']+=($win-$amount+$rebate+$offline);
            }
            $list=array_values($daily); usort($list,static fn(array $a,array $b): int => strcmp($b['bill_date'],$a['bill_date']));
        } else {
            $list=$query->order('bill_date','desc')->select()->toArray();
        }
        // Older deployments do not backfill the bills summary table. Build the
        // same daily view from the source records until a summary exists.
        $recordsQuery=Db::name('bet_records')->where('site_id',$s['site_id'])->where('user_id',$s['user_id']);
        if ($from) $recordsQuery->where('placed_at','>=',$from.' 00:00:00');
        if ($to) $recordsQuery->where('placed_at','<=',$to.' 23:59:59');
        $records=$recordsQuery->select()->toArray();
        if ($records && $lotteryFilter==='') {
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

    /** Convert the provider's result rows to the line shape used by the UI.
     *
     * The provider result is intentionally kept separate from the local
     * parser response, but when it is enabled these rows become the primary
     * preview so the user actually sees what the provider recognized.
     */
    private function thirdPartyCatalogueLines(array $result, string $lottery): array
    {
        $atomic=[];
        foreach ((array)($result['data']['cil']??[]) as $cilKey=>$lotteryRows) {
            $lineCategory=((string)$cilKey==='3'?'体':((string)$cilKey==='4'?'福':($lottery==='排列三'?'体':'福')));
            foreach ((array)($lotteryRows['codeList']??[]) as $type=>$entries) {
                if (!is_array($entries)) continue;
                foreach ($entries as $code=>$entry) {
                    if (!is_array($entry)) continue;
                    $amount=(float)($entry['amount']??0); $count=(int)($entry['totalCount']??1);
                    if ($amount<=0 || $count<1) continue;
                    $code=(string)$code; $type=(int)$type; $selection=''; $play=''; $source=''; $display=[];
                    if ($type===1 || $type===2) {
                        $display[]=$code; $play=$type===1?'口XX':'口口X'; $source=$code==''
                            ? '' : (($type===1?'百':'百').preg_replace('/X$/','',(string)$code).'各'.$amount.'元');
                        // Position catalogue keys are already concrete patterns;
                        // keep them as display tokens and use the corresponding
                        // identity source for the local odds row.
                        if ($type===1) $source='百'.preg_replace('/X$/','',(string)$code).'各'.$amount.'元';
                        else {
                            $pattern=(string)$code; $xpos=strpos($pattern,'X');
                            $source=($xpos===0?'X口口':($xpos===1?'口X口':'口口X')).'各'.$amount.'元';
                        }
                    } elseif ($type===3) {
                        $selection=preg_replace('/\D/','',$code)??''; if ($selection==='') continue;
                        $display[]=$selection.'直'; $play='直'; $source=$selection.'直各'.$amount.'元';
                    } elseif ($type===4) {
                        $selection=preg_replace('/\D/','',$code)??''; if ($selection==='') continue;
                        $display[]=$selection.'胆'; $play='独胆'; $source='独胆'.$selection.'各'.$amount.'元';
                    } elseif ($type===5) {
                        $selection=str_pad($code,2,'0',STR_PAD_LEFT); $play=$selection[0]===$selection[1]?'对子':'双飞';
                        $display[]=$selection.$play; $source=$selection.$play.'各'.$amount.'元';
                    } elseif ($type===6) {
                        $selection=preg_replace('/\D/','',$code)??''; if ($selection==='') continue;
                        $play=count(array_unique(str_split($selection)))===2?'组三':'组六'; $display[]=$selection.$play; $source=$selection.$play.'各'.$amount.'元';
                    } elseif ($type===7 || $type===8 || $type===9) {
                        $selection=preg_replace('/^(?:z[36]m|fsm)/i','',$code)??$code; $selection=preg_replace('/\D/','',$selection)??''; if ($selection==='') continue;
                        $family=$type===7?'组三':($type===8?'组六':'复式'); $words=['1'=>'一','2'=>'二','3'=>'三','4'=>'四','5'=>'五','6'=>'六','7'=>'七','8'=>'八','9'=>'九'];
                        $play=$family.($family==='复式'?($words[(string)strlen($selection)]??strlen($selection)):' '.($words[(string)strlen($selection)]??strlen($selection))).'码';
                        $play=str_replace(' ','',$play);
                        $display[]=(($family==='组三'?'三':'六').' '.$selection); $source=$selection.$play.'各'.$amount.'元';
                    } elseif ($type===10 || $type===11) {
                        if (preg_match('/^[a-z0-9]*?(\d)t(\d+)$/i',$code,$m)!==1) continue;
                        $family=$type===10?'组三':'组六'; $play=$family.'胆拖'; $dragCount=strlen($m[2]); $display[]='胆'.$m[1].'拖'.$m[2].$play; $source=$play.' '.$m[1].'码拖'.$dragCount.' 胆'.$m[1].'拖'.$m[2].'各'.$amount.'元';
                    } elseif ($type===12) {
                        if (preg_match('/(\d)$/',$code,$m)!==1) continue; $play='跨度'.$m[1]; $display[]='跨'.$m[1]; $source=$play.'各'.$amount.'元';
                    } elseif ($type===13) {
                        $map=['hzda'=>'和大','hzx'=>'和小','hzdn'=>'和单','hzs'=>'和双']; $key=strtolower($code);
                        if (isset($map[$key])) {$play=$map[$key];$display[]=$play;$source=$play.'各'.$amount.'元';}
                        elseif (preg_match('/hz(\d{1,2})$/i',$key,$m)===1) {$play='和值'.$m[1];$display[]=$play;$source=$play.'各'.$amount.'元';}
                    } elseif ($type===14) {$play='豹子全包';$display[]='豹包';$source=$play.$amount.'元';}
                    elseif ($type===15 || $type===16) {
                        $selection=preg_replace('/^lzz[36]/i','',$code)??$code; $selection=preg_replace('/\D/','',$selection)??''; if ($selection==='') continue;
                        $family=$type===15?'组三':'组六'; $words=['1'=>'一','2'=>'二','3'=>'三','4'=>'四','5'=>'五','6'=>'六','7'=>'七','8'=>'八','9'=>'九']; $play=$family.'赖'; $display[]=$selection.$play; $source=$selection.($words[(string)strlen($selection)]??strlen($selection)).'码'.$family;
                    } elseif ($type===17) {$play='对子全包';$display[]='对包';$source=$play.$amount.'元';}
                    elseif ($type===18) {
                        if (preg_match('/^[a-z0-9]*?(\d{2})t(\d+)$/i',$code,$m)!==1) continue; $play='组六2胆拖';$display[]='胆'.$m[1].'拖'.$m[2];$source=$play.' 2码拖'.strlen($m[2]).' 胆'.$m[1].'拖'.$m[2].'各'.$amount.'元';
                    } elseif ($type===19) {
                        if (preg_match('/^[a-z]*?(\d)t(\d+)$/i',$code,$m)!==1) continue; $play='单选全胆拖';$display[]='胆'.$m[1].'拖'.$m[2];$source=$play.' 1码拖'.strlen($m[2]).' 胆'.$m[1].'拖'.$m[2].'各'.$amount.'元';
                    }
                    if ($source==='' || $display===[]) continue;
                    // Provider type is a wire-format bucket, not the
                    // business identity.  Different buckets can describe
                    // the same package; include normalized business fields
                    // so one selection is emitted once.
                    $semanticPlay=preg_replace('/\s+/u','',$play)??$play;
                    $semanticNumber=preg_replace('/\s+/u','',trim((string)$display[0]))??(string)$display[0];
                    $atomicKey=$semanticPlay.'|'.number_format($amount/max(1,$count),4,'.','').'|'.$semanticNumber;
                    if (!isset($atomic[$atomicKey])) $atomic[$atomicKey]=['number'=>$display[0],'amount'=>0.0,'count'=>0,'source'=>$source,'play'=>$play,'type'=>$type,'categories'=>[]];
                    $atomic[$atomicKey]['amount']+=$amount; $atomic[$atomicKey]['count']+=$count; $atomic[$atomicKey]['categories'][$lineCategory]=true;
                }
            }
        }
        $catalogue=[];
        foreach ($atomic as $atom) {
            $categories=array_keys($atom['categories']); sort($categories);
            $category=in_array('福',$categories,true)&&in_array('体',$categories,true)?'福体':($categories[0]??($lottery==='排列三'?'体':'福'));
            $unit=(float)$atom['amount']/max(1,(int)$atom['count']);
            $groupKey=$category.'|'.$atom['type'].'|'.$atom['play'].'|'.number_format($unit,4,'.','');
            if (!isset($catalogue[$groupKey])) $catalogue[$groupKey]=['numbers'=>[],'display'=>[],'amount'=>0.0,'count'=>0,'source'=>$atom['source'],'play'=>$atom['play'],'category'=>$category];
            $catalogue[$groupKey]['numbers'][]=$atom['number']; $catalogue[$groupKey]['display'][]=$atom['number'];
            $catalogue[$groupKey]['amount']+=(float)$atom['amount']; $catalogue[$groupKey]['count']+=(int)$atom['count'];
        }
        $lines=[]; $id=1;
        foreach ($catalogue as $group) {
            $numbers=implode(' ',array_values(array_unique($group['numbers']))); $source=$group['source']; $displaySource=$source;
            if (in_array($group['play'],['直','独胆','双飞','对子','组三','组六'],true)) {
                $baseNumbers=implode(' ',array_map(static fn(string $value):string=>preg_replace('/(直|胆|双飞|对子|组三|组六)$/u','',$value)??$value,$group['numbers']));
                $source=($group['play']==='独胆'?'独胆'.$baseNumbers:$baseNumbers.' '.$group['play']).'各'.rtrim(rtrim(number_format($group['amount']/max(1,$group['count']),2,'.',''),'0'),'.').'元';
                $prefix=$group['play']==='直'?'直':($group['play']==='组三'?'三':($group['play']==='组六'?'六':''));
                $displaySource=$prefix!==''?$prefix.' '.$baseNumbers.'各'.rtrim(rtrim(number_format($group['amount']/max(1,$group['count']),2,'.',''),'0'),'.').'元':$source;
            } elseif (preg_match('/^(组三|组六)[一二两三四五六七八九]码$/u',(string)$group['play'],$multiPlay)===1) {
                // Keep the full play name in settlement_text for odds lookup,
                // while the user-facing row uses the reference notation:
                // “三 123456” / “六 123456”.
                $prefix=$multiPlay[1]==='组三'?'三':'六';
                $baseNumbers=implode(' ',array_map(static fn(string $value):string=>preg_replace('/^[三六]\s*/u','',$value)??$value,$group['numbers']));
                $unit=rtrim(rtrim(number_format($group['amount']/max(1,$group['count']),2,'.',''),'0'),'.');
                $displaySource=$prefix.' '.$baseNumbers.'各'.$unit.'元';
            }
            $lines[]=['id'=>$id++,'raw_text'=>$displaySource,'input_text'=>$displaySource,'parse_text'=>$source,'status'=>'success','reason'=>null,
                'number_text'=>$numbers,'display_number_text'=>$numbers,'expanded_number_text'=>$numbers,'category'=>(string)$group['category'],
                'play_type'=>$group['play'],'settlement_text'=>$source,'amount'=>number_format($group['amount'],2,'.',''),'count'=>$group['count'],'stake_count'=>$group['count'],'code_count'=>$group['count'],'provider_catalogue'=>true];
        }
        // The provider keeps a sentence such as “... 福一直一组” as one
        // catalogue row.  The catalogue itself contains separate odds atoms
        // for 直 and 组 so that placement can still apply each play's odds,
        // but the preview must preserve the provider's single-row shape.
        // Keep the atoms in a private field for quickPlace and collapse only
        // the mixed direct/group display rows here.
        $mixed=[];
        foreach ($lines as $index=>$line) {
            $play=(string)($line['play_type']??'');
            if ($play!=='直' && !in_array($play,['组三','组六'],true)) continue;
            $key=(string)($line['category']??'').'|'.number_format((float)$line['amount']/max(1,(int)$line['count']),4,'.','');
            $mixed[$key][]=$index;
        }
        $rawStatement='';
        foreach ((array)($result['data']['rl']??[]) as $row) {
            if (!is_array($row) || ($row['ij']??false)!==true || (int)($row['isSuccess']??0)!==1) continue;
            $candidate=trim((string)($row['txt']??$row['ftxt']??$row['ltxt']??''));
            if ($candidate!=='') { $rawStatement=$candidate; break; }
        }
        foreach ($mixed as $indexes) {
            $plays=[]; foreach ($indexes as $index) $plays[(string)($lines[$index]['play_type']??'')]=true;
            if (!isset($plays['直']) || count($plays)<2) continue;
            $parts=[]; $amount=0.0; $count=0; $numbers=[]; $category=(string)$lines[$indexes[0]]['category'];
            foreach ($indexes as $index) {
                $part=$lines[$index]; $parts[]=$part; $amount+=(float)$part['amount']; $count+=(int)$part['count'];
                foreach (preg_split('/\s+/u',trim((string)$part['number_text']),-1,PREG_SPLIT_NO_EMPTY)?:[] as $number) {
                    $number=preg_replace('/(直|组三|组六)$/u','',$number)??$number; if ($number!=='') $numbers[$number]=true;
                }
            }
            $displaySource=$rawStatement!==''?$rawStatement:implode(' ',array_map(static fn(array $part):string=>(string)$part['raw_text'],$parts));
            $collapsed=['id'=>0,'raw_text'=>$displaySource,'input_text'=>$displaySource,'parse_text'=>$displaySource,'status'=>'success','reason'=>null,
                'number_text'=>implode(' ',array_keys($numbers)),'display_number_text'=>implode(' ',array_keys($numbers)),'expanded_number_text'=>implode(' ',array_keys($numbers)),
                'category'=>$category,'play_type'=>'直组','settlement_text'=>$displaySource,'amount'=>number_format($amount,2,'.',''),'count'=>$count,'stake_count'=>$count,'code_count'=>$count,
                'provider_catalogue'=>true,'provider_place_parts'=>$parts];
            $firstIndex=$indexes[0]; $lines[$firstIndex]=$collapsed;
            foreach (array_slice($indexes,1) as $index) unset($lines[$index]);
        }
        $lines=array_values($lines); foreach ($lines as $index=>&$line) $line['id']=$index+1; unset($line);
        return $lines;
    }

    private function providerLineForLottery(array $line,string $lottery): array
    {
        if ((string)($line['category']??'')!=='福体') { $line['category']=$lottery==='福彩3D'?'福':'体'; return $line; }
        foreach (['count','stake_count','code_count'] as $field) {
            if (!array_key_exists($field,$line)) continue;
            $value=(int)$line[$field];
            if ($value<1 || $value%2!==0) throw new \InvalidArgumentException('福体投注注数无法按彩种拆分');
            $line[$field]=intdiv($value,2);
        }
        $line['amount']=number_format((float)($line['amount']??0)/2,2,'.',''); $line['category']=$lottery==='福彩3D'?'福':'体';
        foreach (['raw_text','input_text','parse_text','settlement_text'] as $field) if (isset($line[$field])) $line[$field]=str_replace('福体',(string)$line['category'],(string)$line[$field]);
        return $line;
    }

    private function thirdPartyLines(array $result, string $lottery): array
    {
        $catalogue=$this->thirdPartyCatalogueLines($result,$lottery);
        $rows=(array)($result['data']['rl']??[]); $lines=[]; $nextId=1;
        if ($catalogue!==[]) {
            // Keep provider-side validation failures beside successful
            // catalogue rows.  A mixed ticket must remain visible as mixed
            // and quickPlace will reject the whole ticket, instead of silently
            // dropping the failed sentence and placing only its valid part.
            foreach ($rows as $row) {
                if (!is_array($row) || (int)($row['isSummary']??0)===1 || !array_key_exists('isSuccess',$row) || (int)$row['isSuccess']===1) continue;
                $raw=trim((string)($row['txt']??$row['ftxt']??$row['ltxt']??''));
                $reasons=$this->thirdPartyRowMessages($row);
                if ($raw==='') $raw=implode('；',$reasons);
                if ($raw==='') continue;
                $lines[]=['id'=>count($catalogue)+count($lines)+1,'raw_text'=>$raw,'input_text'=>$raw,
                    'parse_text'=>trim((string)($row['ltxt']??$raw)),'status'=>'failed','reason'=>$this->thirdPartyRowMessage($row),
                    'reasons'=>$reasons,
                    'number_text'=>'','display_number_text'=>'','expanded_number_text'=>'','category'=>$lottery==='福彩3D'?'福':'体',
                    'play_type'=>'','settlement_text'=>'','amount'=>'0.00','count'=>0,'stake_count'=>0,'code_count'=>0];
            }
            return array_merge($catalogue,$lines);
        }

        // A provider continuation ticket is returned as several amount-less
        // rows followed by one `ij` row containing the complete statement and
        // its aggregate amount/count. Mapping those rows one by one loses the
        // concrete catalogue plays (for example 组三九码 ... 组三两码), and the
        // empty rows then fail the local odds lookup during placement.
        //
        // Use the provider as the authority for success and totals, while the
        // local parser restores the semantic line shape needed by our odds
        // catalogue. Only accept the reconstruction when both count and amount
        // exactly match the provider; otherwise retain the raw provider rows.
        $groupStarts=[]; $groupConsumed=[];
        foreach($rows as $rowIndex=>$row){
            if(!is_array($row) || ($row['ij']??false)!==true || (int)($row['isSuccess']??0)!==1)continue;
            $groupText=trim((string)($row['ftxt']??''));
            if($groupText==='' || !str_contains($groupText,"
"))continue;
            // Provider continuation rows are kept provider-authoritative.
            // Do not reconstruct them through the local parser.
            $parsed=[];
            if($parsed===[])continue;
            $parsedCount=0; $parsedAmount=0.0; $valid=true;
            foreach($parsed as $parsedLine){
                if(($parsedLine['status']??'')!=='success'){$valid=false;break;}
                $parsedCount+=(int)($parsedLine['count']??0);
                $parsedAmount+=(float)($parsedLine['amount']??0);
            }
            $providerCount=(int)($row['tc']??0); $providerAmount=(float)($row['ta']??0);
            if(!$valid || $parsedCount!==$providerCount || abs($parsedAmount-$providerAmount)>0.001)continue;
            $statementLines=array_values(array_filter(preg_split('/\r?
/u',$groupText)?:[],static fn(string $value):bool=>trim($value)!==''));
            $start=max(0,(int)$rowIndex-count($statementLines)+1);
            $groupStarts[$start]=$parsed;
            for($i=$start;$i<=(int)$rowIndex;$i++)$groupConsumed[$i]=true;
        }

        foreach($rows as $rowIndex=>$row){
            if(isset($groupStarts[$rowIndex])){
                foreach($groupStarts[$rowIndex] as $parsedLine){
                    $parsedLine['id']=$nextId++;
                    $parsedLine['provider_grouped']=true;
                    $lines[]=$parsedLine;
                }
                continue;
            }
            if(isset($groupConsumed[$rowIndex]))continue;
            if(!is_array($row) || (int)($row['isSummary']??0)===1)continue;
            $raw=trim((string)($row['txt']??$row['ftxt']??''));
            if($raw==='')continue;
            $display=trim((string)($row['ltxt']??$raw));
            $numberSource=$display.' '.$raw; $numbers=[];
            if(preg_match_all('/(?<!\d)\d{3}(?!\d)/u',$numberSource,$matches))$numbers=array_values(array_unique($matches[0]));
            $play='';
            if(preg_match('/直组|直組/u',$display,$m))$play='直组';
            elseif(preg_match('/组六|组六|组6/u',$display,$m))$play='组六';
            elseif(preg_match('/组三|组三|组3/u',$display,$m))$play='组三';
            elseif(preg_match('/定位/u',$display,$m))$play='定位';
            elseif(preg_match('/直/u',$display,$m))$play='直';
            elseif(preg_match('/组/u',$display,$m))$play='组';
            $status=(int)($row['isSuccess']??0)===1?'success':'failed';
            $amount=number_format((float)($row['ta']??0),2,'.',''); $count=(int)($row['tc']??0);
            $reasons=$status==='success'?[]:$this->thirdPartyRowMessages($row);
            $line=['id'=>$nextId++,'raw_text'=>$raw,'input_text'=>$raw,'parse_text'=>$display,'status'=>$status,
                'reason'=>$status==='success'?null:implode('；',$reasons),'reasons'=>$reasons,
                'number_text'=>implode(' ',$numbers),'display_number_text'=>implode(' ',$numbers),
                'expanded_number_text'=>implode(' ',$numbers),'category'=>$lottery==='福彩3D'?'福':'体','play_type'=>$play,
                'settlement_text'=>$display,'amount'=>$amount,'count'=>$count,'stake_count'=>$count,'code_count'=>$count,
            ];
            if ($status==='success' && count($numbers)>0 && preg_match('/直/u',$display)===1 && preg_match('/组/u',$display)===1) {
                $partCount=(int)floor($count/2); $partAmount=(float)$amount/2; $perNumber=$partCount>0?$partAmount/$partCount:0.0;
                $line['provider_parts']=[];
                foreach (['直','组'] as $part) {
                    $suffixNumbers=array_map(static fn(string $number):string=>$number.$part,$numbers);
                    $partSource=implode(' ',$numbers).' '.$part.'各'.rtrim(rtrim(number_format($perNumber,2,'.',''),'0'),'.').'元 '.($lottery==='福彩3D'?'福':'体');
                    $line['provider_parts'][]=['raw_text'=>$raw,'input_text'=>$raw,'parse_text'=>$partSource,'status'=>'success','reason'=>null,
                        'number_text'=>implode(' ',$suffixNumbers),'display_number_text'=>implode(' ',$suffixNumbers),'expanded_number_text'=>implode(' ',$suffixNumbers),
                        'category'=>$lottery==='福彩3D'?'福':'体','play_type'=>$part,'settlement_text'=>$partSource,
                        'amount'=>number_format($partAmount,2,'.',''),'count'=>$partCount,'stake_count'=>$partCount,'code_count'=>$partCount];
                }
            }
            $lines[]=$line;
        }
        return $lines;
    }

    /** Extract the provider's own validation text without rewriting it. */
    private function providerText(mixed $value): ?string
    {
        if (is_string($value) && trim($value)!=='') return trim($value);
        if (!is_array($value)) return null;

        // Providers have used several names for the same validation message.
        // Read the explicit message fields first so a normal betting text such
        // as `txt`/`ltxt` is never mistaken for an error.
        $preferred=['message','msg','error','err','errmsg','error_message','errorMessage',
            'st','status_text','statusText','detail','reason','tips','tip','notice',
            'description','desc','提示','错误','说明'];
        foreach ($preferred as $key) {
            if (array_key_exists($key,$value)) {
                $candidate=$value[$key];
                if (is_string($candidate) && trim($candidate)!=='') return trim($candidate);
                if (is_array($candidate)) {
                    $found=$this->providerText($candidate);
                    if ($found!==null) return $found;
                }
            }
        }

        // Some responses nest the row/error under data, d, result or rl and
        // use a non-standard key. Search those containers for a string that is
        // clearly an error sentence, while skipping fields that contain the
        // original betting text or display labels.
        foreach (['data','d','result','results','rl','rows','items'] as $key) {
            if (!array_key_exists($key,$value)) continue;
            $nested=$value[$key];
            if (is_array($nested)) {
                $found=$this->providerText($nested);
                if ($found!==null) return $found;
            }
        }
        foreach ($value as $key=>$candidate) {
            if (in_array((string)$key,['txt','ftxt','ltxt','text','input','raw_text','number_text','display_number_text'],true)) continue;
            if (is_string($candidate)) {
                $text=trim($candidate);
                if ($text!=='' && preg_match('/无法识别|不能识别|未识别|识别.*(问题|错误|失败)|语句.*(问题|错误)|金额.*(不一致|错误)|(错误|失败|无效|超出|超过)/u',$text)) return $text;
            }
        }
        return null;
    }

    /** Extract the provider's own validation text without rewriting it. */
    private function thirdPartyRowMessage(array $row): string
    {
        $messages=$this->thirdPartyRowMessages($row);
        if ($messages!==[]) return $messages[0];
        // The reference protocol reports this validation failure as numeric
        // row codes (for example rc/ftp = -102); its UI renders the standard
        // sentence below when no textual field is present.
        return '语句存在问题，无法识别';
    }

    /** Return every provider-side validation message for one result row. */
    private function thirdPartyRowMessages(array $row): array
    {
        $messages=[];
        $preferred=['message','msg','error','err','errmsg','error_message','errorMessage','st','status_text','statusText','detail','reason','tips','tip','notice','description','desc','提示','错误','说明','ec'];
        $collect=function(mixed $value) use (&$collect,&$messages): void {
            if (is_string($value)) {
                $text=trim($value);
                if ($text!=='' && preg_match('/无法识别|不能识别|未识别|语句.*(问题|错误|失败)|金额.*(不一致|错误)|人工确认|未断行|错误|失败|无效|超出|超过/u',$text)===1) $messages[]=$text;
                return;
            }
            if (is_array($value)) foreach ($value as $item) $collect($item);
        };
        foreach ($preferred as $key) if (array_key_exists($key,$row)) $collect($row[$key]);
        // wt=6 is the reference protocol's manual-review marker.
        if ((int)($row['wt']??0)===6) $messages[]='请进行人工确认';
        $messages=array_values(array_unique(array_filter(array_map(static fn(string $value):string=>trim($value),$messages),static fn(string $value):bool=>$value!=='')));
        return $messages!==[]?$messages:['语句存在问题，无法识别'];
    }

    /** Collect all provider validation messages when no usable row exists. */
    private function thirdPartyMessages(array $result): array
    {
        $messages=[];
        foreach ((array)($result['data']['rl']??[]) as $row) {
            if (!is_array($row) || (int)($row['isSummary']??0)===1 || (int)($row['isSuccess']??0)===1) continue;
            foreach ($this->thirdPartyRowMessages($row) as $message) $messages[]=$message;
        }
        if ($messages===[]) {
            $message=$this->providerText($result);
            if ($message!==null) $messages[]=$message;
        }
        $messages=array_values(array_unique($messages));
        return $messages!==[]?$messages:['语句存在问题，无法识别'];
    }

    /** Extract the provider's own validation text without rewriting it. */
    private function thirdPartyMessage(array $result): string
    {
        $message=$this->providerText($result);
        if ($message!==null) return $message;
        $rows=(array)($result['data']['rl']??[]);
        foreach ($rows as $row) if (is_array($row)) return $this->thirdPartyRowMessage($row);
        return '语句存在问题，无法识别';
    }

    /** Keep a reachable provider response authoritative, including errors. */
    private function providerPreviewLines(array $result, string $lottery): array
    {
        $sourceLines=$this->thirdPartyLines($result,$lottery); $lines=[];
        foreach ($sourceLines as $sourceLine) {
            // provider_parts/provider_place_parts are private placement
            // metadata.  Never expand them in the user-facing preview.
            $sourceLine['id']=count($lines)+1; $lines[]=$sourceLine;
        }
        $code=ThirdPartyQuickEntryUtils::responseCode($result);
        if ($code===200 && $lines!==[]) return $lines;
        if ($lines!==[]) return $lines;
        return [[
            'id'=>1,'raw_text'=>'','input_text'=>'','parse_text'=>'','status'=>'failed',
            'reason'=>implode('；',$this->thirdPartyMessages($result)),'reasons'=>$this->thirdPartyMessages($result),'number_text'=>'','display_number_text'=>'',
            'expanded_number_text'=>'','category'=>$lottery==='福彩3D'?'福':'体','play_type'=>'',
            'settlement_text'=>'','amount'=>'0.00','count'=>0,'stake_count'=>0,'code_count'=>0,
        ]];
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
        // A mixed direct/group preview is intentionally displayed as one
        // `直组` row, while the local parser still returns two atomic rows
        // (`直` and `组`). Rebuild that display row from the two atoms before
        // applying the uniqueness guard.
        if($matches===[] && trim((string)($line['play_type']??''))==='直组'){
            $targetNumbers=array_values(array_filter(preg_split('/\s+/u',trim((string)($line['number_text']??'')),-1,PREG_SPLIT_NO_EMPTY)?:[],static fn(string $n):bool=>$n!==''));
            $targetKey=implode(' ',array_map(static fn(string $n):string=>preg_replace('/(直|组|组三|组六)$/u','',$n)??$n,$targetNumbers));
            $parts=[];
            foreach($this->quickLines($raw,$lottery,$tenantId) as $candidate){
                if(($candidate['status']??'')!=='success' || !in_array((string)($candidate['play_type']??''),['直','组'],true))continue;
                $candidateNumbers=preg_split('/\s+/u',trim((string)($candidate['number_text']??'')),-1,PREG_SPLIT_NO_EMPTY)?:[];
                $candidateKey=implode(' ',array_map(static fn(string $n):string=>preg_replace('/(直|组|组三|组六)$/u','',$n)??$n,$candidateNumbers));
                if($candidateKey===$targetKey)$parts[]=$candidate;
            }
            $playKinds=[]; foreach($parts as $part)$playKinds[(string)$part['play_type']]=true;
            if(isset($playKinds['直'])&&isset($playKinds['组'])){
                $combined=$parts[0]; $combined['play_type']='直组';
                $combined['amount']=number_format(array_sum(array_map(static fn(array $part):float=>(float)($part['amount']??0),$parts)),2,'.','');
                foreach(['count','stake_count','code_count'] as $field) if(array_key_exists($field,$combined))$combined[$field]=array_sum(array_map(static fn(array $part):int=>(int)($part[$field]??0),$parts));
                $combined['number_text']=(string)($line['number_text']??''); $combined['display_number_text']=$combined['number_text']; $combined['expanded_number_text']=$combined['number_text'];
                $matches=[$combined];
            }
        }
        if(count($matches)>1){
            $unique=[];
            foreach($matches as $candidate){
                $key=(string)($candidate['play_type']??'').'|'.(string)($candidate['number_text']??'').'|'.number_format((float)($candidate['amount']??0),2,'.','').'|'.(int)($candidate['count']??0);
                $unique[$key]=$candidate;
            }
            $matches=array_values($unique);
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
        $category=$lottery==='福彩3D'?'福':'体';
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
        $s=$this->session($request); $text=trim((string)$request->post('text','')); if (mb_strlen($text)>10000) return $this->reply(null,'投注文本不能超过10000个字符',422); $lottery=trim((string)$request->post('lottery','福彩3D')); if (!in_array($lottery,['福彩3D','排列三'],true)) return $this->reply(null,'彩种无效',422);
        if ($text==='') return $this->reply(['lines'=>[],'count'=>0,'amount'=>'0.00'],'请输入投注文本',422);
        $thirdPartyConfig=ThirdPartyQuickEntryConfig::load((int)$s['tenant_id'],(int)$s['site_id']);
        if (!(bool)$thirdPartyConfig['enabled']) {
            return $this->reply(null,'识别服务暂未启用，请联系管理员',503);
        }
        try {
            $thirdParty=(new ThirdPartyQuickEntryClient($thirdPartyConfig))->recognize($text,$lottery==='排列三'?3:4);
        } catch (\Throwable $e) {
            Log::warning('third-party quick preview unavailable: '.$e->getMessage());
            return $this->reply(null,'识别服务暂时不可用，请点击“生成”重试',503);
        }
        $providerLines=$this->providerPreviewLines($thirdParty,$lottery);
        return $this->reply(['lines'=>$providerLines,'count'=>(int)($thirdParty['data']['tc']??0),'code_count'=>(int)($thirdParty['data']['tc']??0),'amount'=>number_format((float)($thirdParty['data']['ta']??0),2,'.',''),'formatted_text'=>$text]);
    }
    public function quickPlace(Request $request): \think\response\Json
    {
        $s=$this->session($request); if (!(bool)$request->post('confirmed',false)) return $this->reply(null,'请确认下注内容后再提交',422);
        if ((string)Db::name('site_users')->where('id',$s['user_id'])->where('site_id',$s['site_id'])->value('account_state')==='bet_paused') return $this->reply(null,'当前账号已暂停下注',403);
        $text=trim((string)$request->post('text','')); if ($text==='' || mb_strlen($text)>10000) return $this->reply(null,'投注文本无效',422); $lottery=trim((string)$request->post('lottery','福彩3D')); if (!in_array($lottery,['福彩3D','排列三'],true)) return $this->reply(null,'彩种无效',422);
        $thirdPartyConfig=ThirdPartyQuickEntryConfig::load((int)$s['tenant_id'],(int)$s['site_id']);
        if (!(bool)$thirdPartyConfig['enabled']) return $this->reply(null,'识别服务暂未启用，请联系管理员',503);
        try {
            $providerResult=(new ThirdPartyQuickEntryClient($thirdPartyConfig))->recognize($text,$lottery==='排列三'?3:4);
        } catch (\Throwable $e) {
            Log::warning('third-party quick place unavailable: '.$e->getMessage());
            return $this->reply(null,'识别服务暂时不可用，请点击“生成”重试',503);
        }
        $providerAuthoritative=true;
        $formattedText=$text;
        $lines=$this->providerPreviewLines($providerResult,$lottery);
        if (!$lines) return $this->reply(null,'没有可下注的有效内容',422);
        foreach ($lines as $line) if (($line['status']??'')!=='success') {
            return $this->reply(null, $providerAuthoritative ? (string)($line['reason']??$this->thirdPartyMessage($providerResult??[])) : '存在未识别或金额不一致的内容，已取消整单下注', 422);
        }
        $backfillAt=trim((string)$request->post('robot_backfill_at',''));
        $targetIssueParam=trim((string)$request->post('robot_target_issue',''));
        // Only the scheduler's protected worker session may choose a
        // historical timestamp/issue. Normal member requests always use the
        // server clock and the live issue.
        $robotBackfill=(bool)($s['robot_scheduler']??false)
            && ($targetIssueParam!=='' || $backfillAt!=='');
        $backfillTimestamp=$backfillAt!=='' ? strtotime($backfillAt) : false;
        $now=($robotBackfill && $backfillTimestamp!==false)
            ? date('Y-m-d H:i:s',$backfillTimestamp)
            : date('Y-m-d H:i:s');
        $amount=0.0; $count=0; $groups=[];
        try {
            foreach ($lines as $line) {
                $providerParts=$providerAuthoritative
                    ? (is_array($line['provider_place_parts']??null) && $line['provider_place_parts']!==[] ? $line['provider_place_parts'] : (is_array($line['provider_parts']??null) && $line['provider_parts']!==[] ? $line['provider_parts'] : [$line]))
                    : [$line];
                foreach ($providerParts as $sourceLine) foreach ($this->lotteriesForLine($sourceLine,$lottery) as $lineLottery) {
                    $this->assertLotteryPermission($s,$lineLottery,true);
                    $splitLine=$providerAuthoritative ? $this->providerLineForLottery($sourceLine,$lineLottery) : $this->lineForLottery($sourceLine,$lineLottery,(int)$s['tenant_id']);
                    $rule=$this->applyLineLimits($s,$lineLottery,$splitLine);
                    $groups[$lineLottery]['lines'][]=['line'=>$splitLine,'rule'=>$rule];
                    $groups[$lineLottery]['amount']=($groups[$lineLottery]['amount']??0)+(float)$rule['actual'];
                    $groups[$lineLottery]['count']=($groups[$lineLottery]['count']??0)+(int)$splitLine['count'];
                    $amount+=(float)$rule['actual']; $count+=(int)$splitLine['count'];
                }
            }
            foreach ($groups as $lineLottery=>&$group) {
                $control=$this->lotteryControl($s,$lineLottery);
                if (!$robotBackfill && !$this->timingAllowsBet($control)) throw new \InvalidArgumentException($lineLottery.'当前时段禁止下注');
                $lotteryId=(int)Db::name('lotteries')->where('tenant_id',$s['tenant_id'])->where('name',$lineLottery)->where('status',1)->whereNull('deleted_at')->value('id');
                if ($lotteryId<1) throw new \InvalidArgumentException('当前彩种不存在或已停用');
                $targetIssue=$robotBackfill ? $targetIssueParam : '';
                // A deferred historical ticket may only retain its scheduled
                // timestamp. Resolve the first opened issue after that time
                // instead of silently falling through to today's live issue.
                if($robotBackfill && $targetIssue==='' && $backfillAt!=='') {
                    $historicalTarget=Db::name('lottery_histories')->where('lottery_id',$lotteryId)
                        ->where('is_opened',1)->where('open_time','>',$backfillAt)
                        ->whereNotNull('code')->order('open_time asc')->order('id asc')->find();
                    $targetIssue=(string)($historicalTarget['code']??'');
                }
                $nextHistory=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->whereNotNull('next_code')->where('next_code','<>','')->order('open_time desc')->order('id desc')->find();
                $pendingHistory=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('is_opened',0)->where('open_time','>=',$now)->order('open_time asc')->order('id asc')->find();
                $closingTime=$nextHistory['next_open_time']??($pendingHistory['open_time']??null);
                if (!$robotBackfill && ($this->cutoffReached($control) || (!$this->cutoffConfigured($control) && $closingTime && strtotime((string)$closingTime)<=time()))) throw new \InvalidArgumentException($lineLottery.'已封盘，整单未下注');
                if ($robotBackfill) {
                    $target=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('code',$targetIssue)->where('is_opened',1)->find();
                    if (!$target) throw new \InvalidArgumentException($lineLottery.'机器人目标开奖期号无效');
                }
                $issueNo=$robotBackfill ? $targetIssue : (string)($nextHistory['next_code']??($pendingHistory['code']??''));
                if ($issueNo==='') throw new \InvalidArgumentException($lineLottery.'暂无可下注期号，整单未下注');
                $group['lottery_id']=$lotteryId; $group['issue_no']=$issueNo;
            }
            unset($group);
        } catch (\InvalidArgumentException $e) { return $this->reply(null,$e->getMessage(),422); }
        $submissionFingerprint=$this->submissionFingerprint($s,$groups);
        if ($amount<=0) return $this->reply(null,'当前投注已全部停押，暂不能下注',422);
        $user=Db::name('site_users')->where('id',$s['user_id'])->where('site_id',$s['site_id'])->whereNull('deleted_at')->field('id,balance,credit_balance,used_balance,used_balance_date')->find();
        if (!$user) return $this->reply(null,'用户不存在或已停用',404);
        $user=DailyScoreUsage::normalize($user);
        $available=(float)$user['balance']+(float)$user['credit_balance']-(float)$user['used_balance']; if ($amount>$available) return $this->reply(null,'可用余额不足，无法下注',422);
        if (!$this->betSubmissionsAvailable()) {
            try {
                $transactionResult=Db::transaction(function () use ($s,$text,$formattedText,$groups,$amount,$now,$submissionFingerprint): array {
                    $lockedUser=Db::name('site_users')->where('id',(int)$s['user_id'])->where('site_id',(int)$s['site_id'])->lock(true)->find();
                    if (!$lockedUser) throw new \RuntimeException('用户不存在或已停用');
                    $lockedUser=DailyScoreUsage::normalize($lockedUser);
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
                    DailyScoreUsage::change((int)$s['user_id'], $amount);
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
                $lockedUser=DailyScoreUsage::normalize($lockedUser);
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
                DailyScoreUsage::change((int)$s['user_id'], $amount);
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
