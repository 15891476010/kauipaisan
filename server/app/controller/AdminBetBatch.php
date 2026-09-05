<?php
declare(strict_types=1);
namespace app\controller;

use app\service\BetSettlement;
use app\service\CreditLedger;
use app\service\QuickEntryParser;
use app\service\ThirdPartyQuickEntryClient;
use app\service\ThirdPartyQuickEntryConfig;
use think\Request;
use think\facade\Cache;
use think\facade\Db;
use app\service\AuditLogger;

final class AdminBetBatch
{
    private function reply(mixed $data=null, string $message='ok', int $code=0): \think\response\Json
    {
        return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]);
    }

    private function scopedSiteId(Request $request): ?int
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token!==''?Cache::get('token:'.$token):null;
        if (!is_array($session) || ($session['scope']??'')!=='admin') throw new \RuntimeException('未登录或登录已过期');
        if (($session['admin_role']??'platform')==='platform') return null;
        $siteId=(int)($session['site_id']??0);
        if ($siteId<1) throw new \RuntimeException('当前管理员未绑定站点');
        return $siteId;
    }

    private function session(Request $request): array
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token!==''?Cache::get('token:'.$token):null;
        if (!is_array($session) || ($session['scope']??'')!=='admin') throw new \RuntimeException('未登录或登录已过期');
        return $session;
    }

    /** Return the records selected by the operator plus their sibling lottery records. */
    private function expandRecordIds(array $recordIds, ?int $siteId): array
    {
        $recordIds=array_values(array_unique(array_filter(array_map('intval',$recordIds),static fn(int $id): bool=>$id>0)));
        if ($recordIds===[]) return [];
        $query=Db::name('bet_records')->whereIn('id',$recordIds);
        if ($siteId!==null) $query->where('site_id',$siteId);
        $rows=$query->field('id,site_id,submission_id')->select()->toArray();
        if ($rows===[]) return [];
        $submissions=array_values(array_unique(array_filter(array_map('intval',array_column($rows,'submission_id')))));
        if ($submissions===[]) return array_values(array_map('intval',array_column($rows,'id')));
        $all=[];
        foreach ($rows as $row) { $id=(int)$row['id']; $all[$id]=$id; }
        $siblings=Db::name('bet_records')->whereIn('submission_id',$submissions);
        if ($siteId!==null) $siblings->where('site_id',$siteId);
        foreach ($siblings->column('id') as $id) $all[(int)$id]=(int)$id;
        return array_values($all);
    }

    private function editableRecords(array $recordIds, ?int $siteId): array
    {
        $query=Db::name('bet_records')->whereIn('id',$recordIds)->whereIn('status',['pending','won','unwon']);
        if ($siteId!==null) $query->where('site_id',$siteId);
        return $query->field('id,site_id,user_id,issue_no,submission_id,status,sealed,amount,bet_count,source_text,formatted_text')->select()->toArray();
    }

    private function replacementPreview(array $records, string $operation, array $payload): array
    {
        $changes=[]; $skipped=[];
        $from=trim((string)($payload['from']??'')); $to=trim((string)($payload['to']??''));
        $amount=array_key_exists('amount',$payload)?(float)$payload['amount']:null;
        if ($operation==='replace_number' && ($from==='' || $to==='')) throw new \InvalidArgumentException('请填写原号码和新号码');
        if ($operation==='replace_play' && ($from==='' || $to==='')) throw new \InvalidArgumentException('请填写原玩法和新玩法');
        if ($operation==='set_amount' && ($amount===null || !is_finite($amount) || $amount<0)) throw new \InvalidArgumentException('请输入有效金额');
        foreach ($records as $record) {
            $details=Db::name('bet_details')->where('bet_record_id',(int)$record['id'])->order('id asc')->select()->toArray();
            foreach ($details as $detail) {
                $stop=Db::name('user_stop_drops')->where('bet_detail_id',(int)$detail['id'])->order('id asc')->find() ?: [];
                $oldNumber=(string)($detail['number_text']??''); $oldPlay=(string)($stop['play_type']??$detail['category']??''); $oldAmount=(float)($detail['amount']??0);
                $newNumber=$oldNumber; $newPlay=$oldPlay; $newAmount=$oldAmount; $matched=false;
                if ($operation==='replace_number' && str_contains($oldNumber,$from)) { $newNumber=str_replace($from,$to,$oldNumber); $matched=true; }
                elseif ($operation==='replace_play' && ($oldPlay===$from || str_contains($oldPlay,$from))) { $newPlay=str_replace($from,$to,$oldPlay); $matched=true; }
                elseif ($operation==='set_amount') { $newAmount=$amount; $matched=true; }
                if (!$matched) { $skipped[]=['record_id'=>(int)$record['id'],'detail_id'=>(int)$detail['id'],'reason'=>'不匹配']; continue; }
                $changes[]=['record_id'=>(int)$record['id'],'detail_id'=>(int)$detail['id'],'issue_no'=>(string)$record['issue_no'],'old_number'=>$oldNumber,'new_number'=>$newNumber,'old_play'=>$oldPlay,'new_play'=>$newPlay,'old_amount'=>number_format($oldAmount,2,'.',''),'new_amount'=>number_format($newAmount,2,'.','')];
            }
        }
        return ['changes'=>$changes,'skipped'=>$skipped];
    }

    private function buildRecordOptions(?int $siteId): array
    {
        $query=Db::name('bet_records')->whereIn('status',['pending','won','unwon'])->order('id desc')->limit(500);
        if ($siteId!==null) $query->where('site_id',$siteId);
        $records=$query->select()->toArray();
        $userIds=array_values(array_unique(array_filter(array_map('intval',array_column($records,'user_id')))));
        $users=$userIds?Db::name('site_users')->whereIn('id',$userIds)->column('username','id'):[];
        $siteIds=array_values(array_unique(array_filter(array_map('intval',array_column($records,'site_id')))));
        $sites=$siteIds?Db::name('sites')->whereIn('id',$siteIds)->column('name','id'):[];
        foreach ($records as &$record) {
            $details=Db::name('bet_details')->where('bet_record_id',(int)$record['id'])->order('id asc')->select()->toArray();
            $detailIds=array_values(array_map('intval',array_column($details,'id')));
            $stops=$detailIds?Db::name('user_stop_drops')->whereIn('bet_detail_id',$detailIds)->order('id asc')->select()->toArray():[];
            $stopByDetail=[]; foreach($stops as $stop)$stopByDetail[(int)$stop['bet_detail_id']]=$stop;
            $record['record_id']=(int)$record['id']; $record['username']=(string)($users[(int)$record['user_id']]??'未知用户'); $record['site_name']=(string)($sites[(int)$record['site_id']]??'');
            $record['details']=array_map(static function(array $detail)use($stopByDetail):array{ $stop=$stopByDetail[(int)$detail['id']]??[]; return ['detail_id'=>(int)$detail['id'],'lottery'=>(string)($stop['lottery']??''),'number_text'=>(string)($detail['number_text']??''),'play_type'=>(string)($stop['play_type']??$detail['category']??''),'amount'=>number_format((float)($detail['amount']??0),2,'.','')]; },$details);
            $record['amount']=number_format((float)($record['amount']??0),2,'.','');
        } unset($record);
        return $records;
    }

    public function recordOptions(Request $request): \think\response\Json
    {
        return $this->reply(['records'=>$this->buildRecordOptions($this->scopedSiteId($request))]);
    }

    public function preview(Request $request): \think\response\Json
    {
        $siteId=$this->scopedSiteId($request); $data=$request->post();
        $ids=$this->expandRecordIds(is_array($data['record_ids']??null)?$data['record_ids']:[],$siteId);
        if ($ids===[]) throw new \InvalidArgumentException('请选择要修改的主单');
        $records=$this->editableRecords($ids,$siteId); if(count($records)!==count($ids)) throw new \RuntimeException('只能修改未开奖且未封盘的主单，请刷新后重试');
        $result=$this->replacementPreview($records,(string)($data['operation']??''),(array)($data['payload']??[]));
        return $this->reply(['record_ids'=>$ids,'changed_count'=>count($result['changes']),'skipped_count'=>count($result['skipped']),'changes'=>array_slice($result['changes'],0,200),'skipped'=>array_slice($result['skipped'],0,200)]);
    }

    public function apply(Request $request): \think\response\Json
    {
        $siteId=$this->scopedSiteId($request); $session=$this->session($request); $data=$request->post();
        $ids=$this->expandRecordIds(is_array($data['record_ids']??null)?$data['record_ids']:[],$siteId);
        if ($ids===[]) throw new \InvalidArgumentException('请选择要修改的主单');
        $operation=(string)($data['operation']??''); $payload=(array)($data['payload']??[]);
        $changed=Db::transaction(function()use($ids,$siteId,$operation,$payload):int{
            $records=$this->editableRecords($ids,$siteId); if(count($records)!==count($ids)) throw new \RuntimeException('主单状态已变化，请刷新后重试');
            $preview=$this->replacementPreview($records,$operation,$payload); $count=0;
            foreach($preview['changes'] as $change){
                // Query Builder emits `LIMIT 1 FOR UPDATE` for find(), which
                // is rejected by the MariaDB version used in production.
                $detailRows=Db::name('bet_details')->where('id',(int)$change['detail_id'])->lock(true)->select()->toArray();
                $detail=$detailRows[0]??null; if(!$detail)throw new \RuntimeException('明细已变化，请刷新后重试');
                $update=['amount'=>$change['new_amount']]; if($operation==='replace_number')$update['number_text']=$change['new_number']; if($operation==='replace_play')$update['category']=$change['new_play'];
                Db::name('bet_details')->where('id',(int)$change['detail_id'])->update($update);
                $stopQuery=Db::name('user_stop_drops')->where('bet_detail_id',(int)$change['detail_id']); $stopUpdate=['original_amount'=>$change['new_amount'],'actual_amount'=>$change['new_amount']]; if($operation==='replace_number')$stopUpdate['number_text']=$change['new_number']; if($operation==='replace_play')$stopUpdate['play_type']=$change['new_play']; $stopQuery->update($stopUpdate); $count++;
            }
            foreach($ids as $id){$total=(float)Db::name('bet_details')->where('bet_record_id',(int)$id)->sum('amount');$countDetails=(int)Db::name('bet_details')->where('bet_record_id',(int)$id)->count();Db::name('bet_records')->where('id',(int)$id)->update(['amount'=>number_format($total,2,'.',''),'bet_count'=>$countDetails]);}
            return $count;
        });
        AuditLogger::write($session,'update','bet_records',['record_ids'=>$ids,'operation'=>$operation,'payload'=>$payload,'changed'=>$changed],(string)$request->ip());
        return $this->reply(['changed'=>$changed],'主单批量修改完成');
    }

    private function lotteries(?int $siteId): array
    {
        $query=Db::name('lotteries')->alias('l')->where('l.status',1)->whereNull('l.deleted_at');
        if ($siteId!==null) $query->join('site_lotteries sl','sl.lottery_id=l.id')->where('sl.site_id',$siteId);
        return $query->field('l.id,l.name,l.code,l.sort')->order('l.sort asc')->order('l.id asc')->select()->toArray();
    }

    /** Extract editable three-digit numbers and retain any play suffix. */
    private function editableNumberTokens(string $value): array
    {
        $tokens=preg_split('/\s+/',trim($value))?:[];
        $result=[];
        foreach ($tokens as $tokenIndex=>$token) {
            $token=trim((string)$token);
            if ($token==='' || !preg_match('/^(\d{3})(直|组三|组六|组)?$/u',$token,$match)) continue;
            $result[]=['token_index'=>(int)$tokenIndex,'value'=>(string)$match[1],'suffix'=>(string)($match[2]??'')];
        }
        return $result;
    }

    private function currentIssue(array $lottery, ?int $siteId): string
    {
        // The target issue is the lottery's current unopened issue, not the
        // latest issue that happens to have a bet. This keeps the selector
        // useful immediately after a draw, before any user has placed a bet.
        $now=date('Y-m-d H:i:s');
        $pendingQuery=Db::name('lottery_histories')
            ->where('lottery_id',(int)$lottery['id'])
            ->where('is_opened',0)
            ->where('open_time','>=',$now)
            ->order('open_time asc')->order('id asc');
        $pending=$pendingQuery->field('code')->find();
        if ($pending && trim((string)($pending['code']??''))!=='') return trim((string)$pending['code']);

        // If the scheduler has not yet advanced open_time, still expose the
        // first unopened row rather than incorrectly reporting no issue.
        $fallback=Db::name('lottery_histories')
            ->where('lottery_id',(int)$lottery['id'])
            ->where('is_opened',0)
            ->order('open_time asc')->order('id asc')
            ->field('code')->find();
        $fallbackCode=trim((string)($fallback['code']??''));
        if ($fallbackCode!=='') return $fallbackCode;

        // Last-resort fallback for the short window before the scheduler has
        // inserted the next history row: use the previous row's next_code,
        // or increment a numeric issue while preserving its width.
        $latest=Db::name('lottery_histories')->where('lottery_id',(int)$lottery['id'])
            ->where('is_opened',1)->order('open_time desc')->order('id desc')
            ->field('code,next_code')->find();
        $next=trim((string)($latest['next_code']??''));
        if ($next!=='') return $next;
        $lastCode=trim((string)($latest['code']??''));
        if ($lastCode!=='' && ctype_digit($lastCode)) {
            $incremented=(string)((int)$lastCode+1);
            return strlen($incremented)<strlen($lastCode)
                ? str_pad($incremented,strlen($lastCode),'0',STR_PAD_LEFT)
                : $incremented;
        }
        return '';
    }

    public function options(Request $request): \think\response\Json
    {
        $siteId=$this->scopedSiteId($request);
        $lotteries=$this->lotteries($siteId);
        $lotteryId=(int)$request->param('lottery_id',0);
        $lotteryName=trim((string)$request->param('lottery',''));
        $lottery=null;
        foreach ($lotteries as $item) if ((int)$item['id']===$lotteryId) { $lottery=$item; break; }
        if (!$lottery && $lotteryName!=='') foreach ($lotteries as $item) if ((string)$item['name']===$lotteryName) { $lottery=$item; break; }
        if (!$lottery) $lottery=$lotteries[0]??null;
        if (!$lottery) return $this->reply(['lotteries'=>[],'lottery'=>null,'issue_no'=>'','issues'=>[],'users'=>[]]);
        $issues=array_values(array_map('strval',Db::name('lottery_histories')->where('lottery_id',(int)$lottery['id'])->where('is_opened',0)->order('open_time asc')->order('id asc')->limit(100)->column('code')));
        $lotteryName=(string)$lottery['name'];
        $betIssueQuery=Db::name('bet_records')->alias('r')->join('bet_details d','d.bet_record_id=r.id')->leftJoin('user_stop_drops s','s.bet_detail_id=d.id')
            ->whereRaw('(s.lottery = ? OR (s.id IS NULL AND r.source_text LIKE ?))',[$lotteryName,'参考站总货概览主单%'])
            ->whereIn('r.status',['pending','won','unwon'])->whereIn('d.status',['pending','won','unwon']);
        if ($siteId!==null) $betIssueQuery->where('r.site_id',$siteId);
        foreach ($betIssueQuery->distinct(true)->column('r.issue_no') as $betIssue) {
            $betIssue=(string)$betIssue; if ($betIssue!=='' && !in_array($betIssue,$issues,true)) $issues[]=$betIssue;
        }
        $selectedRecordIds=array_values(array_unique(array_filter(array_map('intval',explode(',',(string)$request->param('record_ids',''))),static fn(int $id): bool=>$id>0)));
        $selectedUserIds=[];
        if ($selectedRecordIds!==[]) {
            $selectedRows=Db::name('bet_records')->whereIn('id',$selectedRecordIds)->whereIn('status',['pending','won','unwon']);
            if ($siteId!==null) $selectedRows->where('site_id',$siteId);
            $selectedRows=$selectedRows->field('id,user_id,issue_no')->select()->toArray();
            $selectedRecordIds=array_values(array_map('intval',array_column($selectedRows,'id')));
            $selectedUserIds=array_values(array_unique(array_map('intval',array_column($selectedRows,'user_id'))));
            $selectedIssues=array_values(array_unique(array_map('strval',array_column($selectedRows,'issue_no'))));
            if (count($selectedIssues)===1) $requestIssue=trim((string)$selectedIssues[0]); else $requestIssue='';
        } else $requestIssue=trim((string)$request->param('issue_no',''));
        $issue=$requestIssue!=='' ? $requestIssue : $this->currentIssue($lottery,$siteId);
        if ($issue!=='' && !in_array($issue,$issues,true)) array_unshift($issues,$issue);
        if ($issue==='') return $this->reply(['lotteries'=>$lotteries,'lottery'=>$lottery,'issue_no'=>'','issues'=>$issues,'users'=>[],'selected_record_ids'=>$selectedRecordIds,'selected_user_ids'=>$selectedUserIds]);
        $requestedUsers=array_values(array_unique(array_filter(array_map('intval',explode(',',(string)$request->param('user_ids',''))),static fn(int $id): bool=>$id>0)));
        if ($requestedUsers===[] && $selectedUserIds!==[]) $requestedUsers=$selectedUserIds;

        $query=Db::name('bet_details')->alias('d')
            ->join('bet_records r','r.id=d.bet_record_id')
            ->leftJoin('user_stop_drops s','s.bet_detail_id=d.id')
            ->leftJoin('site_users u','u.id=d.user_id')
            ->leftJoin('sites st','st.id=d.site_id')
            ->whereRaw('(s.lottery = ? OR (s.id IS NULL AND r.source_text LIKE ?))',[$lotteryName,'参考站总货概览主单%'])
            ->where('r.issue_no',$issue)
            ->whereIn('r.status',['pending','won','unwon'])->whereIn('d.status',['pending','won','unwon']);
        if ($siteId!==null) $query->where('d.site_id',$siteId);
        if ($requestedUsers!==[]) $query->whereIn('d.user_id',$requestedUsers);
        $rows=$query->field('d.id,d.user_id,d.site_id,d.number_text,d.amount,d.source_text,r.id AS record_id,r.amount AS record_amount,r.bet_count AS record_bet_count,r.source_text AS record_source_text,r.formatted_text AS record_formatted_text,r.submission_id,u.username,u.display_name,st.name AS site_name')
            ->order('d.site_id asc')->order('d.user_id asc')->order('d.id asc')->select()->toArray();
        $users=[];
        $seenRecords=[];
        foreach ($rows as $row) {
            $userKey=(int)$row['site_id'].'-'.(int)$row['user_id'];
            if (!isset($users[$userKey])) $users[$userKey]=[
                'key'=>$userKey,'user_id'=>(int)$row['user_id'],'site_id'=>(int)$row['site_id'],
                'username'=>(string)($row['username']??'未知用户'),'display_name'=>(string)($row['display_name']??''),
                'site_name'=>(string)($row['site_name']??''),'number_count'=>0,'numbers'=>[],
            ];
            // The batch editor works on the original ticket as one unit. Do
            // not expose or parse its generated detail numbers here: one
            // placeholder row per main record is enough for selecting and
            // editing the complete raw text (including 复式/和值/跨度/沾边赖).
            $recordId=(int)($row['record_id']??0);
            if ($recordId<1 || isset($seenRecords[$userKey][$recordId])) continue;
            $seenRecords[$userKey][$recordId]=true;
            $users[$userKey]['number_count']++;
            if ($requestedUsers===[]) continue;
            $users[$userKey]['numbers'][]=[
                'key'=>$recordId.'-raw','record_id'=>$recordId,'detail_id'=>(int)($row['id']??0),'number_index'=>-1,
                'value'=>'原始注单','amount'=>number_format((float)($row['record_amount']??0),2,'.',''),'source_text'=>(string)($row['source_text']??''),
                'record_source_text'=>(string)($row['record_source_text']??''),'record_formatted_text'=>(string)($row['record_formatted_text']??''),
            ];
        }
        return $this->reply(['lotteries'=>$lotteries,'lottery'=>$lottery,'issue_no'=>$issue,'issues'=>$issues,'selected_record_ids'=>$selectedRecordIds,'selected_user_ids'=>$selectedUserIds,'users'=>array_values($users)]);
    }

    /** Replace one selected three-digit token in the original ticket text.
     * Limit the replacement to one occurrence so two identical selections in
     * the same raw ticket remain independently editable.
     */
    private function replaceRawToken(string $source, string $old, string $new): string
    {
        if ($source==='' || $old==='' || $old===$new) return $source;
        $pattern='/(?<!\d)'.preg_quote($old,'/').'(?!\d)/u';
        $count=0; $result=preg_replace($pattern,$new,$source,1,$count);
        if ($count>0 && is_string($result)) return $result;
        $position=strpos($source,$old);
        return $position===false ? $source : substr_replace($source,$new,$position,strlen($old));
    }

    /** Use the same provider mapping as member quick-entry placement. */
    private function thirdPartyRebuildLines(string $sourceText, string $lotteryName, int $tenantId, int $siteId): array
    {
        $config=ThirdPartyQuickEntryConfig::load($tenantId,$siteId);
        if (!(bool)($config['enabled']??false)) throw new \RuntimeException('当前站点未启用三方识别，无法保存修改后的原始注单');
        try {
            $result=(new ThirdPartyQuickEntryClient($config))->recognize($sourceText,$lotteryName==='排列三'?3:4);
        } catch (\Throwable $error) {
            throw new \RuntimeException('三方识别失败：'.$error->getMessage(),0,$error);
        }

        // UserBusiness already contains the production provider-response
        // mapper used for real member placement. Reuse that exact mapper here
        // so batch editing never develops a second, incompatible rule set.
        $business=new UserBusiness();
        $previewMethod=new \ReflectionMethod(UserBusiness::class,'providerPreviewLines');
        $previewMethod->setAccessible(true);
        $providerLineMethod=new \ReflectionMethod(UserBusiness::class,'providerLineForLottery');
        $providerLineMethod->setAccessible(true);
        $previewLines=$previewMethod->invoke($business,$result,$lotteryName);
        if (!is_array($previewLines) || $previewLines===[]) throw new \InvalidArgumentException('三方识别未返回有效投注内容');

        $targetCategory=$lotteryName==='排列三'?'体':'福'; $lines=[]; $reasons=[];
        foreach ($previewLines as $previewLine) {
            if (!is_array($previewLine)) continue;
            if ((string)($previewLine['status']??'')!=='success') {
                $reason=trim((string)($previewLine['reason']??'')); if ($reason!=='') $reasons[]=$reason;
                continue;
            }
            $parts=is_array($previewLine['provider_place_parts']??null)&&$previewLine['provider_place_parts']!==[]
                ? $previewLine['provider_place_parts']
                : (is_array($previewLine['provider_parts']??null)&&$previewLine['provider_parts']!==[]?$previewLine['provider_parts']:[$previewLine]);
            foreach ($parts as $part) {
                if (!is_array($part)) continue;
                $category=(string)($part['category']??'');
                if ($category!=='' && $category!=='福体' && $category!==$targetCategory) continue;
                $mapped=$providerLineMethod->invoke($business,$part,$lotteryName);
                if (is_array($mapped)) $lines[]=$mapped;
            }
        }
        if ($reasons!==[]) throw new \InvalidArgumentException(implode('；',array_values(array_unique($reasons))));
        if ($lines===[]) throw new \InvalidArgumentException('三方识别未返回当前彩种的有效投注内容');
        return $lines;
    }

    /** Undo the financial effects of a settled record before rebuilding it. */
    private function reopenSettledRecord(array $record): void
    {
        if (!in_array((string)($record['status']??''),['won','unwon'],true)) return;
        $recordId=(int)$record['id']; $siteId=(int)$record['site_id']; $userId=(int)$record['user_id'];
        $oldAmount=(float)($record['amount']??0); $oldWin=(float)($record['win_amount']??0);
        $userRows=Db::name('site_users')->where('id',$userId)->where('site_id',$siteId)->lock(true)->select()->toArray();
        $user=$userRows[0]??null; if (!$user) throw new \RuntimeException('结算用户不存在，无法重新计算');
        $balanceBefore=(float)($user['balance']??0); $balanceAfter=$balanceBefore+$oldAmount-$oldWin;
        Db::name('site_users')->where('id',$userId)->where('site_id',$siteId)->update([
            'balance'=>number_format($balanceAfter,2,'.',''),
            'used_balance'=>Db::raw('used_balance + '.number_format($oldAmount,2,'.','')),
            'updated_at'=>date('Y-m-d H:i:s'),
        ]);
        if ($oldWin>0) CreditLedger::write(
            ['tenant_id'=>(int)$record['tenant_id'],'site_id'=>$siteId],
            (int)($user['organization_id']??0)?:null,'user',$userId,$userId,$recordId,null,(string)$record['issue_no'],
            -$oldWin,$balanceBefore,$balanceAfter,'修改注单撤销原中奖结算','settlement'
        );

        // Sum the net share ledger for this record. This also handles a record
        // that has already been recalculated before: old settlement and prior
        // reversal entries cancel, leaving only the currently active shares.
        $shareRows=Db::name('organization_credit_ledger')->where('related_bet_record_id',$recordId)
            ->where('account_type','organization')->where('source_type','settlement_share')->select()->toArray();
        $netShares=[];
        foreach ($shareRows as $shareRow) {
            $organizationId=(int)($shareRow['account_id']??0); if ($organizationId<1) continue;
            $delta=(float)($shareRow['amount']??0)*((string)($shareRow['direction']??'in')==='out'?-1:1);
            $netShares[$organizationId]=($netShares[$organizationId]??0)+$delta;
        }
        foreach ($netShares as $organizationId=>$netShare) {
            if (abs($netShare)<0.005) continue;
            $nodeRows=Db::name('organization_nodes')->where('id',(int)$organizationId)->lock(true)->select()->toArray();
            $node=$nodeRows[0]??null; if (!$node) continue;
            $before=(float)($node['balance']??0); $change=-$netShare; $after=$before+$change;
            Db::name('organization_nodes')->where('id',(int)$organizationId)->update(['balance'=>number_format($after,2,'.',''),'updated_at'=>date('Y-m-d H:i:s')]);
            CreditLedger::organizationSettlement($record,(int)$organizationId,$change,$before,$after,'修改注单撤销原结算占成',['recalculation'=>true]);
        }

        $billDate=substr((string)($record['placed_at']??''),0,10);
        if ($billDate!=='') {
            $billRows=Db::name('bills')->where('site_id',$siteId)->where('user_id',$userId)->where('bill_date',$billDate)->lock(true)->select()->toArray();
            $bill=$billRows[0]??null;
            if ($bill) Db::name('bills')->where('id',(int)$bill['id'])->update([
                'bet_count'=>max(0,(int)($bill['bet_count']??0)-(int)($record['bet_count']??0)),
                'amount'=>number_format(max(0,(float)($bill['amount']??0)-$oldAmount),2,'.',''),
                'win_amount'=>number_format(max(0,(float)($bill['win_amount']??0)-$oldWin),2,'.',''),
                'profit'=>number_format((float)($bill['profit']??0)-($oldWin-$oldAmount),2,'.',''),
            ]);
        }
    }

    /** Rebuild one record from its edited original ticket text. */
    private function rebuildRawRecord(int $recordId, string $lotteryName, string $issue, ?int $siteId, string $sourceText): bool
    {
        $query=Db::name('bet_records')->where('id',$recordId)->where('issue_no',$issue)->whereIn('status',['pending','won','unwon']);
        if ($siteId!==null) $query->where('site_id',$siteId);
        $recordRows=$query->field('id,tenant_id,site_id,user_id,submission_id,board_code,issue_no,placed_at,status,sealed,amount,bet_count,win_amount,source_text,formatted_text')->lock(true)->select()->toArray();
        $record=$recordRows[0]??null;
        if (!$record) throw new \RuntimeException('原始注单已不可修改，请刷新后重试');
        $wasSettled=in_array((string)$record['status'],['won','unwon'],true);
        $sourceText=trim($sourceText); if ($sourceText==='') throw new \InvalidArgumentException('原始注单不能为空');
        $lines=$this->thirdPartyRebuildLines($sourceText,$lotteryName,(int)$record['tenant_id'],(int)$record['site_id']);
        $details=Db::name('bet_details')->where('bet_record_id',$recordId)->order('id asc')->select()->toArray();
        /*
         * An edited raw ticket is a new calculation of the whole pending
         * submission.  The parser is allowed to produce a different number
         * of play lines (for example one combined line becoming two lines),
         * so the old one-to-one line-count guard must not reject it.
         * Release the old interception reservations first, then replace the
         * detail/stop rows and allocate reservations for the new rows.
         */
        $lotteryId=(int)Db::name('lotteries')->where('tenant_id',(int)$record['tenant_id'])->where('name',$lotteryName)->where('status',1)->whereNull('deleted_at')->value('id');
        if ($lotteryId<1) throw new \RuntimeException('当前彩种不存在或已停用');
        if ($wasSettled) $this->reopenSettledRecord($record);
        (new \app\service\InterceptionAllocator())->releaseForRecord($recordId);
        Db::name('agent_interceptions')->where('bet_record_id',$recordId)->delete();
        $detailIds=array_values(array_map('intval',array_column($details,'id')));
        if ($detailIds!==[]) Db::name('user_stop_drops')->whereIn('bet_detail_id',$detailIds)->delete();
        if ($detailIds!==[]) Db::name('bet_details')->whereIn('id',$detailIds)->delete();
        $settlement=new BetSettlement(); $total=0.0; $count=0; $boardCode=(string)($record['board_code']??'A');
        foreach ($lines as $index=>$line) {
            $numberText=trim((string)($line['number_text']??'')); if ($numberText==='') throw new \InvalidArgumentException('原始注单包含无法生成号码的内容');
            $settlementText=trim((string)($line['settlement_text']??$line['parse_text']??$line['raw_text']??$numberText));
            $category=(string)($line['category']??''); $play=(string)($line['play_type']??$category);
            $amount=number_format(max(0,(float)($line['amount']??0)),2,'.','');
            if ((float)$amount<=0) continue;
            $odds=$settlement->oddsRowFor($lotteryId,$settlementText,$boardCode);
            if (!is_array($odds) || !array_key_exists('odds',$odds) || !is_numeric($odds['odds'])) {
                throw new \InvalidArgumentException('修改后的玩法无法唯一匹配赔率，请检查玩法和盘口设置');
            }
            $oddsValue=is_array($odds)&&array_key_exists('odds',$odds)&&is_numeric($odds['odds'])?number_format((float)$odds['odds'],4,'.',''):null;
            $detailData=['tenant_id'=>(int)$record['tenant_id'],'site_id'=>(int)$record['site_id'],'user_id'=>(int)$record['user_id'],'bet_record_id'=>$recordId,
                'board_code'=>$boardCode,'issue_no'=>$issue,'number_text'=>$numberText,'category'=>$category,'amount'=>$amount,'odds'=>$oddsValue,
                'win_amount'=>'0.00','rebate'=>'0.00','status'=>'pending','matched_count'=>0,'placed_at'=>(string)($record['placed_at']??date('Y-m-d H:i:s')),'source_text'=>$settlementText];
            $detailId=(int)Db::name('bet_details')->insertGetId($detailData);
            Db::name('user_stop_drops')->insert(['tenant_id'=>(int)$record['tenant_id'],'site_id'=>(int)$record['site_id'],'user_id'=>(int)$record['user_id'],'bet_detail_id'=>$detailId,
                'board_code'=>$boardCode,'lottery'=>$lotteryName,'issue_no'=>$issue,'number_text'=>$numberText,'play_type'=>$play,'stop_type'=>'none',
                'original_amount'=>$amount,'actual_amount'=>$amount,'stop_amount'=>'0.00','original_odds'=>$oddsValue,'actual_odds'=>$oddsValue,'drop_odds'=>'0.0000',
                'source_text'=>$settlementText,'placed_at'=>(string)($record['placed_at']??date('Y-m-d H:i:s')),'created_at'=>date('Y-m-d H:i:s')]);
            (new \app\service\InterceptionAllocator())->allocate(['tenant_id'=>(int)$record['tenant_id'],'site_id'=>(int)$record['site_id'],'user_id'=>(int)$record['user_id'],'lottery_id'=>$lotteryId,
                'board_code'=>$boardCode,'issue_no'=>$issue,'bet_record_id'=>$recordId,'bet_detail_id'=>$detailId,'number_text'=>$numberText,'amount'=>(float)$amount,'odds'=>$odds]);
            $total+=(float)$amount; $count+=(int)($line['count']??$line['stake_count']??1);
        }
        if ($count<1) throw new \InvalidArgumentException('原始注单未生成有效投注明细');
        $formatted=(new QuickEntryParser())->formatText($sourceText);
        if ($wasSettled) {
            $amountDifference=$total-(float)$record['amount'];
            if (abs($amountDifference)>=0.005) {
                $userRows=Db::name('site_users')->where('id',(int)$record['user_id'])->where('site_id',(int)$record['site_id'])->lock(true)->select()->toArray();
                $user=$userRows[0]??null; if (!$user) throw new \RuntimeException('结算用户不存在，无法调整重算金额');
                $before=(float)$user['balance']+(float)$user['credit_balance']-(float)$user['used_balance'];
                Db::name('site_users')->where('id',(int)$record['user_id'])->where('site_id',(int)$record['site_id'])->update([
                    'used_balance'=>Db::raw($amountDifference>0?'used_balance + '.number_format($amountDifference,2,'.',''):'GREATEST(used_balance - '.number_format(abs($amountDifference),2,'.','').', 0)'),
                    'updated_at'=>date('Y-m-d H:i:s'),
                ]);
                CreditLedger::write(['tenant_id'=>(int)$record['tenant_id'],'site_id'=>(int)$record['site_id']],(int)($user['organization_id']??0)?:null,
                    'user',(int)$record['user_id'],(int)$record['user_id'],$recordId,null,$issue,-$amountDifference,$before,$before-$amountDifference,'修改注单调整下注金额','bet');
            }
        }
        Db::name('bet_records')->where('id',$recordId)->update(['source_text'=>$sourceText,'formatted_text'=>$formatted,'amount'=>number_format($total,2,'.',''),'bet_count'=>$count,'win_amount'=>'0.00','status'=>'pending']);
        $submissionId=(int)($record['submission_id']??0);
        if ($submissionId>0 && Db::query("SHOW TABLES LIKE 'bet_submissions'")!==[]) {
            $submissionRows=Db::name('bet_records')->where('submission_id',$submissionId)->select()->toArray();
            $submissionAmount=0.0; $submissionCount=0; $submissionWin=0.0; $submissionSealed=0; $submissionStatus='pending';
            foreach ($submissionRows as $submissionRow) {
                $submissionAmount+=(float)($submissionRow['amount']??0); $submissionCount+=(int)($submissionRow['bet_count']??0);
                $submissionWin+=(float)($submissionRow['win_amount']??0); $submissionSealed=max($submissionSealed,(int)($submissionRow['sealed']??0));
                $rowStatus=(string)($submissionRow['status']??'pending');
                if ($rowStatus==='refunded') $submissionStatus='refunded';
                elseif ($submissionStatus==='pending' && $rowStatus==='won') $submissionStatus='won';
                elseif ($submissionStatus==='pending' && $rowStatus==='unwon') $submissionStatus='unwon';
                if ($rowStatus==='pending') $submissionStatus='pending';
            }
            if ($submissionStatus!=='refunded' && $submissionWin>0) $submissionStatus='won';
            Db::name('bet_submissions')->where('id',$submissionId)->update(['source_text'=>$sourceText,'formatted_text'=>$formatted,
                'amount'=>number_format($submissionAmount,2,'.',''),'bet_count'=>$submissionCount,'win_amount'=>number_format($submissionWin,2,'.',''),
                'status'=>$submissionStatus,'sealed'=>$submissionSealed]);
        }
        return $wasSettled;
    }

    private function resettleRebuiltRecord(int $recordId, string $lotteryName, string $issue): void
    {
        $lotteryId=(int)Db::name('lotteries')->where('name',$lotteryName)->where('status',1)->whereNull('deleted_at')->value('id');
        if ($lotteryId<1) return;
        $historyRows=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('code',$issue)->select()->toArray();
        $history=$historyRows[0]??null;
        if (!$history || (int)($history['is_opened']??0)!==1) return;
        (new BetSettlement())->settleForHistory($history,['id'=>$lotteryId,'name'=>$lotteryName]);
    }

    public function replace(Request $request): \think\response\Json
    {
        $siteId=$this->scopedSiteId($request);
        $data=$request->put();
        $lotteryId=(int)($data['lottery_id']??0); $issue=trim((string)($data['issue_no']??''));
        $lottery=null;
        foreach ($this->lotteries($siteId) as $item) if ((int)$item['id']===$lotteryId) { $lottery=$item; break; }
        if (!$lottery) throw new \InvalidArgumentException('请选择有效彩种');
        if ($issue==='') throw new \RuntimeException('请选择需要修改的期号');
        $rawRecords=$data['records']??null;
        if (is_array($rawRecords) && $rawRecords!==[]) {
            $settledIds=[];
            $changed=Db::transaction(function() use ($rawRecords,$lottery,$issue,$siteId,&$settledIds): int {
                $changed=0; $seen=[];
                foreach ($rawRecords as $rawRecord) {
                    if (!is_array($rawRecord)) continue;
                    $recordId=(int)($rawRecord['record_id']??0); if ($recordId<1 || isset($seen[$recordId])) continue;
                    $seen[$recordId]=true; if ($this->rebuildRawRecord($recordId,(string)$lottery['name'],$issue,$siteId,(string)($rawRecord['source_text']??''))) $settledIds[]=$recordId; $changed++;
                }
                if ($changed<1) throw new \InvalidArgumentException('请选择需要保存的原始注单');
                return $changed;
            });
            foreach ($settledIds as $settledId) $this->resettleRebuiltRecord((int)$settledId,(string)$lottery['name'],$issue);
            return $this->reply(['changed'=>$changed,'resettled'=>count($settledIds)],'原始注单保存并重算完成');
        }
        $replacement=[];
        foreach (['hundreds'=>0,'tens'=>1,'units'=>2] as $field=>$position) {
            $value=trim((string)($data['replacements'][$field]??''));
            if ($value!=='' && !preg_match('/^\d$/',$value)) throw new \InvalidArgumentException('替换数字必须是0到9的单个数字');
            if ($value!=='') $replacement[$position]=$value;
        }
        if ($replacement===[]) throw new \InvalidArgumentException('请至少输入一个需要替换的位数');
        $selections=$data['selections']??null;
        if (!is_array($selections) || $selections===[] || count($selections)>5000) throw new \InvalidArgumentException('请选择需要替换的号码');
        $selected=[];
        foreach ($selections as $selection) {
            if (!is_array($selection)) continue;
            $detailId=(int)($selection['detail_id']??0); $numberIndex=(int)($selection['number_index']??-1);
            if ($detailId>0 && $numberIndex>=0) $selected[$detailId][$numberIndex]=true;
        }
        if ($selected===[]) throw new \InvalidArgumentException('请选择需要替换的号码');
        $changed=Db::transaction(function () use ($selected,$replacement,$lottery,$issue,$siteId): int {
            $updates=[]; $rawUpdates=[]; $changed=0;
            foreach ($selected as $detailId=>$indexes) {
                $query=Db::name('bet_details')->alias('d')->join('bet_records r','r.id=d.bet_record_id')
                    ->where('d.id',$detailId)->where('d.issue_no',$issue)->where('d.status','pending')->where('r.status','pending');
                if ($siteId!==null) $query->where('d.site_id',$siteId);
                $detailRows=$query->field('d.id,d.number_text,d.bet_record_id,r.source_text AS record_source_text,r.formatted_text AS record_formatted_text,r.submission_id')->lock(true)->select()->toArray();
                $detail=$detailRows[0]??null;
                if (!$detail) throw new \RuntimeException('选中的号码已不可修改，请刷新后重试');
                $stopRows=Db::name('user_stop_drops')->where('bet_detail_id',$detailId)->where('lottery',(string)$lottery['name'])->select()->toArray();
                $stop=$stopRows[0]??null;
                if (!$stop) throw new \RuntimeException('选中的号码不属于当前彩种');
                $editableTokens=$this->editableNumberTokens((string)$detail['number_text']);
                foreach (array_keys($indexes) as $index) {
                    if (!isset($editableTokens[$index])) throw new \RuntimeException('选中的号码位置已变化，请刷新后重试');
                    $tokenIndex=(int)$editableTokens[$index]['token_index'];
                    $chars=str_split((string)$editableTokens[$index]['value']);
                    foreach ($replacement as $position=>$value) $chars[$position]=$value;
                    $next=implode('',$chars);
                    if ($next!==(string)$editableTokens[$index]['value']) {
                        $oldToken=(string)$editableTokens[$index]['value'];
                        $tokens=preg_split('/\s+/',trim((string)$detail['number_text']))?:[];
                        $tokens[$tokenIndex]=$next.(string)$editableTokens[$index]['suffix'];
                        $detail['number_text']=implode(' ',$tokens);
                        $recordId=(int)$detail['bet_record_id'];
                        if (!isset($rawUpdates[$recordId])) $rawUpdates[$recordId]=[
                            'source'=>(string)($detail['record_source_text']??''),
                            'formatted'=>(string)($detail['record_formatted_text']??''),
                            'submission_id'=>(int)($detail['submission_id']??0),
                        ];
                        $rawUpdates[$recordId]['source']=$this->replaceRawToken($rawUpdates[$recordId]['source'],$oldToken,$next);
                        $rawUpdates[$recordId]['formatted']=$this->replaceRawToken($rawUpdates[$recordId]['formatted'],$oldToken,$next);
                        Db::name('agent_interceptions')->where('bet_detail_id',$detailId)->where('number_key',$oldToken)->update(['number_key'=>$next]);
                        $editableTokens[$index]['value']=$next;
                        $changed++;
                    }
                }
                $updates[$detailId]=(string)$detail['number_text'];
            }
            foreach ($updates as $detailId=>$numberText) {
                Db::name('bet_details')->where('id',$detailId)->update(['number_text'=>$numberText]);
                Db::name('user_stop_drops')->where('bet_detail_id',$detailId)->where('lottery',(string)$lottery['name'])->update(['number_text'=>$numberText]);
            }
            foreach ($rawUpdates as $recordId=>$raw) {
                $recordUpdate=['source_text'=>(string)$raw['source']];
                if ((string)$raw['formatted']!=='') $recordUpdate['formatted_text']=(string)$raw['formatted'];
                Db::name('bet_records')->where('id',(int)$recordId)->update($recordUpdate);
                $submissionId=(int)($raw['submission_id']??0);
                if ($submissionId>0 && Db::query("SHOW TABLES LIKE 'bet_submissions'")!==[]) {
                    $submissionUpdate=['source_text'=>(string)$raw['source']];
                    if ((string)$raw['formatted']!=='') $submissionUpdate['formatted_text']=(string)$raw['formatted'];
                    Db::name('bet_submissions')->where('id',$submissionId)->update($submissionUpdate);
                }
            }
            return $changed;
        });
        return $this->reply(['changed'=>$changed],'批量替换完成');
    }
}
