<?php
declare(strict_types=1);
namespace app\controller;

use app\service\BetSettlement;
use app\service\QuickEntryParser;
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
        $query=Db::name('bet_records')->whereIn('id',$recordIds)->where('status','pending')->where('sealed',0);
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
        $query=Db::name('bet_records')->where('status','pending')->where('sealed',0)->order('id desc')->limit(500);
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
                $detail=Db::name('bet_details')->where('id',(int)$change['detail_id'])->lock(true)->find(); if(!$detail)throw new \RuntimeException('明细已变化，请刷新后重试');
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
            ->where('r.status','pending')->where('r.sealed',0)->where('d.status','pending');
        if ($siteId!==null) $betIssueQuery->where('r.site_id',$siteId);
        foreach ($betIssueQuery->distinct(true)->column('r.issue_no') as $betIssue) {
            $betIssue=(string)$betIssue; if ($betIssue!=='' && !in_array($betIssue,$issues,true)) $issues[]=$betIssue;
        }
        $selectedRecordIds=array_values(array_unique(array_filter(array_map('intval',explode(',',(string)$request->param('record_ids',''))),static fn(int $id): bool=>$id>0)));
        $selectedUserIds=[];
        if ($selectedRecordIds!==[]) {
            $selectedRows=Db::name('bet_records')->whereIn('id',$selectedRecordIds)->where('status','pending')->where('sealed',0);
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
            ->where('r.status','pending')->where('d.status','pending');
        if ($siteId!==null) $query->where('d.site_id',$siteId);
        if ($requestedUsers!==[]) $query->whereIn('d.user_id',$requestedUsers);
        $rows=$query->field('d.id,d.user_id,d.site_id,d.number_text,d.amount,d.source_text,r.id AS record_id,r.source_text AS record_source_text,r.formatted_text AS record_formatted_text,r.submission_id,u.username,u.display_name,st.name AS site_name')
            ->order('d.site_id asc')->order('d.user_id asc')->order('d.id asc')->select()->toArray();
        $users=[];
        foreach ($rows as $row) {
            $numbers=array_map(static fn(array $item): string=>(string)$item['value'],$this->editableNumberTokens((string)($row['number_text']??'')));
            if ($numbers===[]) continue;
            $userKey=(int)$row['site_id'].'-'.(int)$row['user_id'];
            if (!isset($users[$userKey])) $users[$userKey]=[
                'key'=>$userKey,'user_id'=>(int)$row['user_id'],'site_id'=>(int)$row['site_id'],
                'username'=>(string)($row['username']??'未知用户'),'display_name'=>(string)($row['display_name']??''),
                'site_name'=>(string)($row['site_name']??''),'number_count'=>0,'numbers'=>[],
            ];
            $unitAmount=(float)($row['amount']??0)/max(1,count($numbers));
            $users[$userKey]['number_count'] += count($numbers);
            if ($requestedUsers===[]) continue;
            foreach ($numbers as $index=>$number) $users[$userKey]['numbers'][]=[
                'key'=>(int)$row['id'].'-'.$index,'record_id'=>(int)$row['record_id'],'detail_id'=>(int)$row['id'],'number_index'=>$index,
                'value'=>$number,'amount'=>number_format($unitAmount,2,'.',''),'source_text'=>(string)($row['source_text']??''),
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

    /** Rebuild one pending record from its edited original ticket text. */
    private function rebuildRawRecord(int $recordId, string $lotteryName, string $issue, ?int $siteId, string $sourceText): void
    {
        $query=Db::name('bet_records')->where('id',$recordId)->where('issue_no',$issue)->where('status','pending')->where('sealed',0);
        if ($siteId!==null) $query->where('site_id',$siteId);
        $record=$query->field('id,tenant_id,site_id,user_id,submission_id,source_text,formatted_text')->lock(true)->find();
        if (!$record) throw new \RuntimeException('原始注单已不可修改，请刷新后重试');
        $sourceText=trim($sourceText); if ($sourceText==='') throw new \InvalidArgumentException('原始注单不能为空');
        $unit=(float)Db::name('lotteries')->where('tenant_id',(int)$record['tenant_id'])->where('name',$lotteryName)->where('status',1)->whereNull('deleted_at')->value('unit_stake');
        $lines=(new QuickEntryParser())->parse($sourceText,$lotteryName,$unit>0?$unit:2.0);
        $lines=array_values(array_filter($lines,static fn(array $line): bool=>(string)($line['status']??'')==='success'));
        if ($lines===[]) throw new \InvalidArgumentException('原始注单未识别到有效投注内容');
        $details=Db::name('bet_details')->where('bet_record_id',$recordId)->order('id asc')->select()->toArray();
        // Provider tickets such as “福一直一组” are stored as one detail
        // even though the local parser exposes two atomic play lines. Keep
        // the original detail shape and recompute its combined number/amount.
        if (count($details)===1 && count($lines)>1) {
            $first=$lines[0]; $numbers=[]; $amount=0.0; $count=0;
            foreach ($lines as $line) {
                foreach (preg_split('/\s+/u',trim((string)($line['number_text']??'')),-1,PREG_SPLIT_NO_EMPTY)?:[] as $number) $numbers[]=$number;
                $amount+=(float)($line['amount']??0); $count+=(int)($line['count']??0);
            }
            $first['number_text']=implode(' ',$numbers); $first['amount']=number_format($amount,2,'.',''); $first['count']=$count;
            $first['stake_count']=$count; $first['play_type']='直组'; $first['settlement_text']=$sourceText; $lines=[$first];
        }
        if (count($lines)!==count($details)) throw new \InvalidArgumentException('修改后的原始注单拆分数量发生变化，请保持原玩法结构后再保存');
        $settlement=new BetSettlement(); $total=0.0; $count=0;
        foreach ($details as $index=>$detail) {
            $line=$lines[$index]; $numberText=trim((string)($line['number_text']??'')); if ($numberText==='') throw new \InvalidArgumentException('原始注单包含无法生成号码的内容');
            $oldTokens=$this->editableNumberTokens((string)($detail['number_text']??'')); $newTokens=$this->editableNumberTokens($numberText);
            $oldCount=max(1,count($oldTokens)); $newCount=max(1,count($newTokens)); $ratio=$newCount/$oldCount;
            // A pure number replacement must not discard the existing stop/drop
            // calculation. Preserve the actual amount when the token count is
            // unchanged; only use the parser amount when the edited ticket
            // deliberately changes its number count.
            $parsedAmount=(float)($line['amount']??0);
            $newAmount=number_format($newCount===$oldCount?(float)($detail['amount']??0):($parsedAmount>0?$parsedAmount:(float)($detail['amount']??0)*$ratio),2,'.','');
            $settlementText=trim((string)($line['settlement_text']??$line['parse_text']??$line['raw_text']??$numberText));
            $category=(string)($line['category']??$detail['category']??''); $play=(string)($line['play_type']??$category);
            $lotteryId=(int)Db::name('lotteries')->where('tenant_id',(int)$record['tenant_id'])->where('name',$lotteryName)->where('status',1)->whereNull('deleted_at')->value('id');
            $odds=$lotteryId>0?$settlement->oddsRowFor($lotteryId,$settlementText):null;
            $detailUpdate=['number_text'=>$numberText,'category'=>$category,'amount'=>$newAmount,'source_text'=>$settlementText];
            if (is_array($odds) && array_key_exists('odds',$odds) && is_numeric($odds['odds'])) $detailUpdate['odds']=number_format((float)$odds['odds'],4,'.','');
            Db::name('bet_details')->where('id',(int)$detail['id'])->update($detailUpdate);
            $stopQuery=Db::name('user_stop_drops')->where('bet_detail_id',(int)$detail['id'])->where('lottery',$lotteryName);
            $stop=$stopQuery->lock(true)->find();
            if ($stop) {
                $stopUpdate=['number_text'=>$numberText,'play_type'=>$play,'source_text'=>$settlementText,
                    'original_amount'=>number_format((float)($stop['original_amount']??$detail['amount'])*$ratio,2,'.',''),
                    'actual_amount'=>$newAmount,'stop_amount'=>number_format((float)($stop['stop_amount']??0)*$ratio,2,'.','')];
                if (is_array($odds) && array_key_exists('odds',$odds) && is_numeric($odds['odds'])) {
                    $stopUpdate['original_odds']=number_format((float)$odds['odds'],4,'.','');
                    $stopUpdate['actual_odds']=number_format((float)$odds['odds'],4,'.','');
                }
                $stopQuery->update($stopUpdate);
            }
            $interceptions=Db::name('agent_interceptions')->where('bet_detail_id',(int)$detail['id'])->order('id asc')->select()->toArray();
            if (count($interceptions)===count($newTokens)) foreach ($interceptions as $interceptionIndex=>$interception) Db::name('agent_interceptions')->where('id',(int)$interception['id'])->update(['number_key'=>(string)$newTokens[$interceptionIndex]['value'],'bet_amount'=>number_format((float)($interception['bet_amount']??0)*$ratio,2,'.','')]);
            $total+=(float)$newAmount; $count+=$newCount;
        }
        $formatted=(new QuickEntryParser())->formatText($sourceText);
        Db::name('bet_records')->where('id',$recordId)->update(['source_text'=>$sourceText,'formatted_text'=>$formatted,'amount'=>number_format($total,2,'.',''),'bet_count'=>$count]);
        $submissionId=(int)($record['submission_id']??0);
        if ($submissionId>0 && Db::query("SHOW TABLES LIKE 'bet_submissions'")!==[]) Db::name('bet_submissions')->where('id',$submissionId)->update(['source_text'=>$sourceText,'formatted_text'=>$formatted,'amount'=>number_format($total,2,'.',''),'bet_count'=>$count]);
    }

    public function replace(Request $request): \think\response\Json
    {
        $siteId=$this->scopedSiteId($request);
        $data=$request->put();
        $lotteryId=(int)($data['lottery_id']??0); $issue=trim((string)($data['issue_no']??''));
        $lottery=null;
        foreach ($this->lotteries($siteId) as $item) if ((int)$item['id']===$lotteryId) { $lottery=$item; break; }
        if (!$lottery) throw new \InvalidArgumentException('请选择有效彩种');
        if ($issue==='' || $issue!==$this->currentIssue($lottery,$siteId)) throw new \RuntimeException('当前期号已变化，请刷新后重新选择');
        $rawRecords=$data['records']??null;
        if (is_array($rawRecords) && $rawRecords!==[]) {
            $changed=Db::transaction(function() use ($rawRecords,$lottery,$issue,$siteId): int {
                $changed=0; $seen=[];
                foreach ($rawRecords as $rawRecord) {
                    if (!is_array($rawRecord)) continue;
                    $recordId=(int)($rawRecord['record_id']??0); if ($recordId<1 || isset($seen[$recordId])) continue;
                    $seen[$recordId]=true; $this->rebuildRawRecord($recordId,(string)$lottery['name'],$issue,$siteId,(string)($rawRecord['source_text']??'')); $changed++;
                }
                if ($changed<1) throw new \InvalidArgumentException('请选择需要保存的原始注单');
                return $changed;
            });
            return $this->reply(['changed'=>$changed],'原始注单保存并重算完成');
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
                $detail=$query->field('d.id,d.number_text,d.bet_record_id,r.source_text AS record_source_text,r.formatted_text AS record_formatted_text,r.submission_id')->lock(true)->find();
                if (!$detail) throw new \RuntimeException('选中的号码已不可修改，请刷新后重试');
                $stop=Db::name('user_stop_drops')->where('bet_detail_id',$detailId)->where('lottery',(string)$lottery['name'])->lock(true)->find();
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
