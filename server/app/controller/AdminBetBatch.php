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

    /**
     * Detail-level rows for every editable record of one issue. Shared by the
     * batch options endpoint and the robot planner so both see the same set.
     */
    private function issueDetailRows(array $lottery, string $issue, ?int $siteId): array
    {
        $lotteryName=(string)$lottery['name'];
        $query=Db::name('bet_details')->alias('d')
            ->join('bet_records r','r.id=d.bet_record_id')
            ->leftJoin('user_stop_drops s','s.bet_detail_id=d.id')
            ->leftJoin('site_users u','u.id=d.user_id')
            ->leftJoin('sites st','st.id=d.site_id')
            ->whereRaw('(s.lottery = ? OR (s.id IS NULL AND r.source_text LIKE ?))',[$lotteryName,'参考站总货概览主单%'])
            ->where('r.issue_no',$issue)
            ->whereIn('r.status',['pending','won','unwon'])->whereIn('d.status',['pending','won','unwon']);
        if ($siteId!==null) $query->where('d.site_id',$siteId);
        // The full row set stays unfiltered: per-member and per-node totals
        // must cover every bettor of this issue even before users are picked.
        return $query->field('d.id,d.user_id,d.site_id,d.number_text,d.amount,d.odds AS detail_odds,d.win_amount AS detail_win,d.status AS detail_status,d.source_text,s.actual_odds,s.play_type AS detail_play_type,r.id AS record_id,r.amount AS record_amount,r.bet_count AS record_bet_count,r.status AS record_status,r.win_amount AS record_win,r.board_code,r.source_text AS record_source_text,r.formatted_text AS record_formatted_text,r.submission_id,r.placed_at,u.username,u.display_name,u.organization_id,st.name AS site_name')
            ->order('d.site_id asc')->order('d.user_id asc')->order('d.id asc')->select()->toArray();
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
            ->whereIn('r.status',['pending','won','unwon'])->whereIn('d.status',['pending','won','unwon'])
            // Only issues that exist in this lottery's own history may enter
            // the selector; otherwise foreign-format codes (a 排列三 ticket
            // written with a 福彩-style issue, or leftover test issues like
            // 28xxx) would leak into the dropdown and can never be settled.
            ->whereRaw('r.issue_no IN (SELECT code FROM lottery_histories WHERE lottery_id = ?)',[(int)$lottery['id']]);
        if ($siteId!==null) $betIssueQuery->where('r.site_id',$siteId);
        $betIssues=[];
        foreach ($betIssueQuery->distinct(true)->column('r.issue_no') as $betIssue) {
            $betIssue=(string)$betIssue; if ($betIssue!=='' && !in_array($betIssue,$issues,true)) $betIssues[]=$betIssue;
        }
        // History rows arrive in draw order; bet-derived issues come back in
        // arbitrary row order, so sort them explicitly (newest first).
        usort($betIssues,static function(string $a,string $b):int{return $b<=>$a;});
        foreach($betIssues as $betIssue) $issues[]=$betIssue;
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
        // 预开奖号码：operator knows the draw before the platform syncs it.
        // Every pending detail is then evaluated against it so totals and
        // the winning-first ordering match the real settlement outcome.
        $draw=preg_replace('/\D/','',(string)$request->param('draw',''));

        $rows=$this->issueDetailRows($lottery,$issue,$siteId);

        // Group details per record, then evaluate each pending record
        // against the predicted draw using the real settlement path.
        $settlement=new BetSettlement();
        $recordMap=[];
        foreach ($rows as $row) {
            $recordId=(int)($row['record_id']??0);
            if ($recordId<1) continue;
            if (!isset($recordMap[$recordId])) $recordMap[$recordId]=[
                'user_key'=>(int)$row['site_id'].'-'.(int)$row['user_id'],
                'amount'=>(float)($row['record_amount']??0),'win'=>(float)($row['record_win']??0),
                'status'=>(string)($row['record_status']??'pending'),'board_code'=>(string)($row['board_code']??'A'),
                'source'=>(string)($row['record_source_text']??''),'details'=>[],
            ];
            $recordMap[$recordId]['details'][]=$row;
        }
        $predictedWins=[];
        // Settled records already know their win; expose it even without a
        // predicted draw so sorting and totals work for opened issues too.
        foreach ($recordMap as $recordId=>$record)
            if ($record['status']!=='pending') $predictedWins[$recordId]=$record['win'];
        $winTokens=[];
        if ($draw!=='') {
            // An entered predicted draw takes precedence over the real result:
            // evaluate every record against it, settled records included, so
            // 预中奖/统计 reflect what the operator intends the draw to be.
            foreach ($recordMap as $recordId=>$record) {
                $win=0.0;$unknown=false;$tokens=[];
                foreach ($record['details'] as $detail) {
                    try {
                        $eval=$settlement->evaluateDetail(
                            ['id'=>(int)$detail['id'],'number_text'=>(string)($detail['number_text']??''),'source_text'=>(string)($detail['source_text']??''),
                             'amount'=>(float)($detail['amount']??0),'odds'=>$detail['detail_odds']??null,'board_code'=>$record['board_code']],
                            ['actual_odds'=>$detail['actual_odds']??null],
                            (int)$lottery['id'],$draw,$record['source']);
                        $win+=$eval['win'];
                        // 中奖明细的非三位 token 是中奖表达式本身（独胆/胆拖/定位/和值
                        // 等），返回给前端做高亮；三位号码已由预开奖号本身覆盖。
                        if ($eval['win']>0.005) {
                            foreach (preg_split('/\s+/u',(string)($detail['number_text']??'')) ?: [] as $tk) {
                                if ($tk==='' || mb_strlen($tk)<2 || preg_match('/^\d{3}(直|组三|组六|组)?$/u',$tk)===1) continue;
                                $tokens[]=$tk;
                            }
                        }
                    } catch (\Throwable) { $unknown=true; }
                }
                $predictedWins[$recordId]=$unknown?null:$win;
                $winTokens[$recordId]=array_values(array_unique($tokens));
            }
        }

        $users=[];
        $userStats=[];
        $seenRecords=[];
        foreach ($rows as $row) {
            $userKey=(int)$row['site_id'].'-'.(int)$row['user_id'];
            if (!isset($users[$userKey])) $users[$userKey]=[
                'key'=>$userKey,'user_id'=>(int)$row['user_id'],'site_id'=>(int)$row['site_id'],
                'username'=>(string)($row['username']??'未知用户'),'display_name'=>(string)($row['display_name']??''),
                'site_name'=>(string)($row['site_name']??''),'organization_id'=>(int)($row['organization_id']??0),
                'number_count'=>0,'numbers'=>[],
            ];
            // The batch editor works on the original ticket as one unit. Do
            // not expose or parse its generated detail numbers here: one
            // placeholder row per main record is enough for selecting and
            // editing the complete raw text (including 复式/和值/跨度/沾边赖).
            $recordId=(int)($row['record_id']??0);
            if ($recordId<1 || isset($seenRecords[$userKey][$recordId])) continue;
            $seenRecords[$userKey][$recordId]=true;
            $users[$userKey]['number_count']++;
            $record=$recordMap[$recordId];
            if (!isset($userStats[$userKey])) $userStats[$userKey]=['bet'=>0.0,'win'=>0.0,'unknown'=>false];
            $userStats[$userKey]['bet']+=$record['amount'];
            if (array_key_exists($recordId,$predictedWins)) {
                if ($predictedWins[$recordId]===null) $userStats[$userKey]['unknown']=true;
                else $userStats[$userKey]['win']+=$predictedWins[$recordId];
            } else $userStats[$userKey]['unknown']=true;
            if ($requestedUsers===[] || !in_array((int)$row['user_id'],$requestedUsers,true)) continue;
            $predicted=$predictedWins[$recordId]??null;
            $users[$userKey]['numbers'][]=[
                'key'=>$recordId.'-raw','record_id'=>$recordId,'detail_id'=>(int)($row['id']??0),'number_index'=>-1,
                'value'=>'原始注单','amount'=>number_format((float)($row['record_amount']??0),2,'.',''),'source_text'=>(string)($row['source_text']??''),
                'record_source_text'=>(string)($row['record_source_text']??''),'record_formatted_text'=>(string)($row['record_formatted_text']??''),
                'record_status'=>$record['status'],'predicted_win'=>$predicted===null?null:number_format($predicted,2,'.',''),
                'win_tokens'=>$winTokens[$recordId]??[],
            ];
        }
        // Organization tree for the hierarchical picker. Members carry their
        // node id + path so the frontend can roll subtree totals up per node.
        $siteIds=array_values(array_unique(array_map('intval',array_column($rows,'site_id'))));
        $nodePaths=[];
        $tree=[];
        if ($siteIds!==[]) {
            $nodes=Db::name('organization_nodes')->whereIn('site_id',$siteIds)->where('status',1)->whereNull('deleted_at')
                ->field('id,site_id,parent_id,level,path,name')->order('depth asc')->order('id asc')->select()->toArray();
            $siteNames=Db::name('sites')->whereIn('id',$siteIds)->column('name','id');
            foreach ($nodes as $node) {
                $nodePaths[(int)$node['id']]=(string)($node['path']??'');
                $tree[]=['id'=>(int)$node['id'],'site_id'=>(int)$node['site_id'],'site_name'=>(string)($siteNames[(int)$node['site_id']]??''),
                    'parent_id'=>(int)$node['parent_id'],'level'=>(string)($node['level']??''),
                    'label'=>\app\service\OrganizationHierarchy::LABELS[(string)($node['level']??'')]??(string)($node['level']??''),
                    'name'=>(string)($node['name']??''),'path'=>(string)($node['path']??'')];
            }
        }
        foreach ($users as $userKey=>$user) {
            $orgId=(int)$user['organization_id'];
            $users[$userKey]['org_path']=$orgId>0?($nodePaths[$orgId]??''):'';
            $stats=$userStats[$userKey]??['bet'=>0.0,'win'=>0.0,'unknown'=>true];
            $users[$userKey]['stats']=[
                'bet'=>number_format($stats['bet'],2,'.',''),
                'win'=>$stats['unknown']?null:number_format($stats['win'],2,'.',''),
                'profit'=>$stats['unknown']?null:number_format($stats['win']-$stats['bet'],2,'.',''),
            ];
        }
        $robotUserIds=[];
        if ($users!==[]) {
            $allUserIds=array_values(array_unique(array_map(static fn(array $user):int=>(int)$user['user_id'],$users)));
            foreach (Db::name('robot_accounts')->whereIn('user_id',$allUserIds)->column('user_id') as $robotUserId)
                $robotUserIds[(int)$robotUserId]=true;
            foreach ($users as $userKey=>$user) $users[$userKey]['is_robot']=isset($robotUserIds[(int)$user['user_id']]);
        }
        return $this->reply(['lotteries'=>$lotteries,'lottery'=>$lottery,'issue_no'=>$issue,'issues'=>$issues,'draw'=>$draw,'tree'=>$tree,'selected_record_ids'=>$selectedRecordIds,'selected_user_ids'=>$selectedUserIds,'users'=>array_values($users)]);
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

    /**
     * Dry-run edited original tickets against a predicted draw. Parses each
     * supplied source text through the same third-party rebuild path used on
     * save, then evaluates the generated lines with the settlement engine.
     * Nothing is written; per-record errors are returned instead of failing
     * the whole batch.
     */
    public function drawPreview(Request $request): \think\response\Json
    {
        $siteId=$this->scopedSiteId($request);
        $data=$request->post();
        $lotteryId=(int)($data['lottery_id']??0); $issue=trim((string)($data['issue_no']??''));
        $lottery=null;
        foreach ($this->lotteries($siteId) as $item) if ((int)$item['id']===$lotteryId) { $lottery=$item; break; }
        if (!$lottery) throw new \InvalidArgumentException('请选择有效彩种');
        if ($issue==='') throw new \InvalidArgumentException('请选择期号');
        $draw=preg_replace('/\D/','',(string)($data['draw']??''));
        if ($draw==='') throw new \InvalidArgumentException('请输入预开奖号码');
        $rawRecords=$data['records']??null;
        if (!is_array($rawRecords) || $rawRecords===[] || count($rawRecords)>200) throw new \InvalidArgumentException('请选择需要预览的原始注单');
        $lotteryName=(string)$lottery['name']; $lotteryId=(int)$lottery['id'];
        $settlement=new BetSettlement(); $results=[];
        foreach ($rawRecords as $rawRecord) {
            if (!is_array($rawRecord)) continue;
            $recordId=(int)($rawRecord['record_id']??0); $sourceText=(string)($rawRecord['source_text']??'');
            if ($recordId<1) continue;
            try {
                $query=Db::name('bet_records')->where('id',$recordId)->where('issue_no',$issue)->whereIn('status',['pending','won','unwon']);
                if ($siteId!==null) $query->where('site_id',$siteId);
                $record=$query->field('id,tenant_id,site_id,user_id,board_code,source_text')->find();
                if (!$record) throw new \RuntimeException('主单不存在或已不可修改');
                if (trim($sourceText)==='') throw new \InvalidArgumentException('原始注单不能为空');
                $lines=$this->thirdPartyRebuildLines($sourceText,$lotteryName,(int)$record['tenant_id'],(int)$record['site_id']);
                $boardCode=(string)($record['board_code']??'A'); $recordSource=(string)($record['source_text']??'');
                $total=0.0; $win=0.0;
                foreach ($lines as $line) {
                    $numberText=trim((string)($line['number_text']??'')); if ($numberText==='') continue;
                    $settlementText=trim((string)($line['settlement_text']??$line['parse_text']??$line['raw_text']??$numberText));
                    $amount=(float)($line['amount']??0); if ($amount<=0) continue;
                    $odds=$settlement->oddsRowFor($lotteryId,$settlementText,$boardCode);
                    if (!is_array($odds) || !array_key_exists('odds',$odds) || !is_numeric($odds['odds']) || (float)$odds['odds']<=0)
                        throw new \InvalidArgumentException('玩法无法唯一匹配赔率，请检查玩法和盘口设置');
                    $eval=$settlement->evaluateParsedLine($numberText,$settlementText,$amount,(float)$odds['odds'],$draw,$recordSource);
                    $total+=$amount; $win+=$eval['win'];
                }
                $results[]=['record_id'=>$recordId,'amount'=>number_format($total,2,'.',''),'win'=>number_format($win,2,'.',''),
                    'profit'=>number_format($win-$total,2,'.',''),'won'=>$win>0];
            } catch (\Throwable $error) {
                $results[]=['record_id'=>$recordId,'error'=>$error->getMessage()];
            }
        }
        return $this->reply(['results'=>$results]);
    }

    /** Undo the financial effects of a settled record before rebuilding it. */
    private function reopenSettledRecord(array $record): void
    {
        if (!in_array((string)($record['status']??''),['won','unwon'],true)) return;
        $recordId=(int)$record['id']; $siteId=(int)$record['site_id']; $userId=(int)$record['user_id'];
        $oldAmount=(float)($record['amount']??0); $oldWin=(float)($record['win_amount']??0);
        $userRows=Db::name('site_users')->where('id',$userId)->where('site_id',$siteId)->lock(true)->select()->toArray();
        $user=$userRows[0]??null; if (!$user) throw new \RuntimeException('结算用户不存在，无法重新计算');
        // Settlement is reporting-only: placing the bet charged used_balance
        // once and settling never moved balance. Reopening must therefore not
        // recompute balance from amount/win — that would phantom-debit a won
        // ticket and push the balance negative. Reverse only the movement a
        // ledger row actually recorded (old-era settlement rows whose stored
        // before/after differ), and stay silent otherwise so the rebuild
        // leaves no visible trace on the member account.
        $netMovement=0.0;
        $movementRows=Db::name('organization_credit_ledger')->where('related_bet_record_id',$recordId)
            ->where('account_type','user')->where('account_id',$userId)->select()->toArray();
        foreach ($movementRows as $movementRow) {
            $delta=CreditLedger::recordedMovement($movementRow);
            if (abs($delta)>=0.005) $netMovement+=$delta;
        }
        if (abs($netMovement)>=0.005) {
            $balanceBefore=(float)($user['balance']??0); $balanceAfter=$balanceBefore-$netMovement;
            Db::name('site_users')->where('id',$userId)->where('site_id',$siteId)->update([
                'balance'=>number_format($balanceAfter,2,'.',''),
                'updated_at'=>date('Y-m-d H:i:s'),
            ]);
            CreditLedger::write(
                ['tenant_id'=>(int)$record['tenant_id'],'site_id'=>$siteId],
                (int)($user['organization_id']??0)?:null,'user',$userId,$userId,$recordId,null,(string)$record['issue_no'],
                -$netMovement,$balanceBefore,$balanceAfter,'修改注单撤销原结算','settlement'
            );
        }

        // Sum the net share ledger for this record. This also handles a record
        // that has already been recalculated before: old settlement and prior
        // reversal entries cancel, leaving only the currently active shares.
        $shareRows=Db::name('organization_credit_ledger')->where('related_bet_record_id',$recordId)
            ->where('account_type','organization')->where('source_type','settlement_share')->select()->toArray();
        $netShares=[]; $supersededShareIds=[];
        foreach ($shareRows as $shareRow) {
            $delta=CreditLedger::recordedMovement($shareRow);
            if (abs($delta)<0.005) {
                // Bookkeeping-only share rows (balance_before == balance_after)
                // describe a settlement that is about to be superseded. If left
                // in place every recalculation stacks another batch and reports
                // multiply-count the shares; deleting them leaves the ledger
                // identical to a single fresh settlement for this record.
                $supersededShareIds[]=(int)$shareRow['id'];
                continue;
            }
            // Reverse only the movement actually applied to the node balance.
            // Replaying bookkeeping amounts would subtract funds the balance
            // never received.
            $organizationId=(int)($shareRow['account_id']??0); if ($organizationId<1) continue;
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
        if ($supersededShareIds!==[]) Db::name('organization_credit_ledger')->whereIn('id',$supersededShareIds)->delete();

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
            // The original stake still occupies the member's daily usage, so
            // only the delta is applied — and only when the bet belongs to the
            // current business day. Usage from an earlier day was already
            // reset and must not leak into today's counter.
            if (abs($amountDifference)>=0.005 && substr((string)($record['placed_at']??''),0,10)===\app\service\DailyScoreUsage::today()) {
                $userRows=Db::name('site_users')->where('id',(int)$record['user_id'])->where('site_id',(int)$record['site_id'])->lock(true)->select()->toArray();
                $user=$userRows[0]??null; if (!$user) throw new \RuntimeException('结算用户不存在，无法调整重算金额');
                $before=(float)$user['balance']+(float)$user['credit_balance']-(float)$user['used_balance'];
                \app\service\DailyScoreUsage::change((int)$record['user_id'],$amountDifference);
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

    // ------------------------------------------------------------------
    // 层级自动改码：按组织子树把会员注单调整到目标盈亏
    // ------------------------------------------------------------------

    /**
     * Load every editable record of one issue and evaluate it against the
     * predicted draw. Returns [recordId => record] with user/site metadata,
     * `details` rows, per-detail `eval_win` and the record-level `cur_win`.
     */
    private function robotIssueContext(array $lottery, string $issue, string $draw, ?int $siteId): array
    {
        $rows=$this->issueDetailRows($lottery,$issue,$siteId);
        $settlement=new BetSettlement();
        $records=[];
        foreach ($rows as $row) {
            $recordId=(int)($row['record_id']??0);
            if ($recordId<1) continue;
            if (!isset($records[$recordId])) $records[$recordId]=[
                'record_id'=>$recordId,'user_id'=>(int)$row['user_id'],'site_id'=>(int)$row['site_id'],
                'username'=>(string)($row['username']??''),'display_name'=>(string)($row['display_name']??''),
                'organization_id'=>(int)($row['organization_id']??0),'board_code'=>(string)($row['board_code']??'A'),
                'source'=>(string)($row['record_source_text']??''),'formatted'=>(string)($row['record_formatted_text']??''),
                'status'=>(string)($row['record_status']??'pending'),'amount'=>(float)($row['record_amount']??0),
                'win'=>(float)($row['record_win']??0),'submission_id'=>(int)($row['submission_id']??0),
                'placed_at'=>(string)($row['placed_at']??''),'details'=>[],
            ];
            $records[$recordId]['details'][]=$row;
        }
        foreach ($records as &$record) {
            $win=0.0;$unknown=false;
            foreach ($record['details'] as $index=>$detail) {
                $detailWin=null;$detailOdds=null;
                if ($draw!=='') {
                    // 输入了预开奖号码：所有注单（含已结算）按预开奖号试算
                    try {
                        $eval=$settlement->evaluateDetail(
                            ['id'=>(int)$detail['id'],'number_text'=>(string)($detail['number_text']??''),'source_text'=>(string)($detail['source_text']??''),
                             'amount'=>(float)($detail['amount']??0),'odds'=>$detail['detail_odds']??null,'board_code'=>$record['board_code']],
                            ['actual_odds'=>$detail['actual_odds']??null],(int)$lottery['id'],$draw,$record['source']);
                        $detailWin=(float)$eval['win'];
                        $detailOdds=$eval['odds']===null?null:(float)$eval['odds'];
                    } catch (\Throwable) { $unknown=true; }
                } elseif ($record['status']!=='pending') {
                    $detailWin=(float)($detail['detail_win']??0);
                    $detailOdds=$detail['detail_odds']===null?null:(float)$detail['detail_odds'];
                } else $unknown=true;
                $record['details'][$index]['eval_win']=$detailWin;
                $record['details'][$index]['eval_odds']=$detailOdds;
                if ($detailWin===null) $unknown=true; else $win+=$detailWin;
            }
            $record['cur_win']=$unknown?null:$win;
        }
        unset($record);
        return $records;
    }

    /**
     * Simulate replacing every editable three-digit token of one record with
     * the predicted draw. Play expressions stay untouched: 直 stays 直,
     * 组三 stays 组三 — only the digit body changes. Details without a
     * replaceable token (胆拖/和值/定位/复式/全包 etc.) keep their current
     * outcome. Returns per-detail results plus the rewritten raw source.
     */
    private function robotFlipSimulation(array $record, string $draw, int $lotteryId, BetSettlement $settlement): array
    {
        $source=$record['source'];
        $replaced=[];
        $details=[];
        foreach ($record['details'] as $detail) {
            $current=$detail['eval_win'];
            $entry=['detail_id'=>(int)$detail['id'],'flippable'=>false,'win'=>$current,
                'old_number'=>(string)$detail['number_text'],'new_number'=>(string)$detail['number_text'],
                'new_detail_source'=>(string)$detail['source_text'],'pairs'=>[],
                'odds'=>$detail['eval_odds']??null];
            // 已中奖的明细不再翻号：同一明细里再造一个开奖号会产生重复选号，
            // 多出来的中奖缺口交给金额缩放补。
            if ($current!==null && $current>0.005) { $details[]=$entry; continue; }
            if ($current!==null) {
                foreach ($this->robotRewriteSpecs($detail,$draw) as $spec) {
                    // Patch the record's raw text through candidate pairs; each
                    // variant list covers alternate raw wordings of the pick.
                    $trial=$source;$pending=$replaced;$applied=[];$ok=true;
                    foreach ($spec['pairs'] as $pair) {
                        $done=false;
                        foreach (array_merge([$pair],$pair['alts']??[]) as $variant) {
                            $old=(string)$variant['old'];$new=(string)$variant['new'];
                            if ($old===''||$old===$new) { $done=true; break; }
                            $patched=$this->replaceRawToken($trial,$old,$new);
                            if ($patched!==$trial) { $trial=$patched;$pending[$old]=true;$applied[]=['old'=>$old,'new'=>$new];$done=true;break; }
                            // A sibling detail may already have consumed the only
                            // raw occurrence of this shared token.
                            if (isset($pending[$old])) { $done=true; break; }
                        }
                        if (!$done) { $ok=false; break; }
                    }
                    if (!$ok) continue;
                    $newDetailSource=(string)$detail['source_text'];
                    foreach (($spec['source_pairs']??$spec['pairs']) as $pair) {
                        foreach (array_merge([$pair],$pair['alts']??[]) as $variant) {
                            $patched=$this->replaceRawToken($newDetailSource,(string)$variant['old'],(string)$variant['new']);
                            if ($patched!==$newDetailSource) { $newDetailSource=$patched; break; }
                        }
                    }
                    try {
                        $eval=$settlement->evaluateDetail(
                            ['id'=>(int)$detail['id'],'number_text'=>$spec['new_number'],'source_text'=>$newDetailSource,
                             'amount'=>(float)$detail['amount'],'odds'=>$detail['detail_odds'],'board_code'=>$record['board_code']],
                            ['actual_odds'=>$detail['actual_odds']??null],$lotteryId,$draw,$record['source']);
                        $w=(float)$eval['win'];
                    } catch (\Throwable) { continue; }
                    if ($w>(float)$current+0.005) {
                        $entry['flippable']=true;$entry['win']=$w;$entry['new_number']=$spec['new_number'];
                        $entry['new_detail_source']=$newDetailSource;$entry['pairs']=$applied;
                        $entry['odds']=$eval['odds']===null?null:(float)$eval['odds'];
                        $source=$trial;$replaced=$pending;
                        break;
                    }
                }
            }
            $details[]=$entry;
        }
        $flipWin=0.0;$known=true;
        foreach ($details as $d) { if ($d['win']===null) $known=false; else $flipWin+=$d['win']; }
        return ['details'=>$details,'win'=>$known?$flipWin:null,'new_source'=>$source,'changed'=>$source!==$record['source']];
    }

    /**
     * Candidate rewrites that make one detail win under $draw without changing
     * its play structure. Each spec: new_number (bet_details.number_text),
     * pairs (fragments patched in the record's raw source), source_pairs
     * (fragments patched in the detail's own source_text). Pair entries may
     * carry 'alts' fallbacks for alternate raw wordings.
     */
    private function robotRewriteSpecs(array $detail, string $draw): array
    {
        $number=trim((string)$detail['number_text']);
        $dsource=(string)$detail['source_text'];
        $digits=str_split($draw);
        $unique=array_values(array_unique($digits));
        $sum=array_sum(array_map('intval',$digits));
        $span=max($digits)-min($digits);
        $specs=[];
        // Pad a required digit set to $len with non-draw digits first, so
        // multi-code selections keep their declared code count.
        $fill=function(array $required,int $len)use($digits):string{
            $set=array_values(array_unique($required));
            for($d=0;$d<=9 && count($set)<$len;$d++) {
                $c=(string)$d;
                if (!in_array($c,$set,true) && !in_array($c,$digits,true)) $set[]=$c;
            }
            for ($i=0;count($set)<$len;$i++) $set[]=$digits[$i%3];
            return implode('',array_slice($set,0,$len));
        };
        $tokens=$number===''?[]:(preg_split('/\s+/',$number)?:[]);
        $patchPositionLists=function(string $fragSource)use($digits):array{
            $pairs=[];
            if (preg_match_all('/([百十个])(?:位)?\s*([0-9]+)/u',$fragSource,$pm,PREG_SET_ORDER)!==false)
                foreach ($pm as $p) {
                    $index=['百'=>0,'十'=>1,'个'=>2][$p[1]];$list=(string)$p[2];
                    if (str_contains($list,$digits[$index])) continue;
                    $frag=(string)$p[0];
                    $newFrag=preg_replace('/\d+$/u',substr($list,0,-1).$digits[$index],$frag);
                    $alt=str_contains($frag,'位')
                        ? ['old'=>str_replace('位','',$frag),'new'=>str_replace('位','',$newFrag)]
                        : ['old'=>preg_replace('/^([百十个])/u','$1位',$frag),'new'=>preg_replace('/^([百十个])/u','$1位',$newFrag)];
                    $pairs[]=['old'=>$frag,'new'=>$newFrag,'alts'=>[$alt]];
                }
            return $pairs;
        };

        // 1) 定位：明细源文本带 百/十/个 选号列表 —— 保持长度、换入开奖位数字；
        //    展开组合只改一注为开奖号，不要把整串号码都改成同一个
        $posPairs=$patchPositionLists($dsource);
        if ($posPairs!==[] || ($dsource!=='' && preg_match('/[百十个]/u',$dsource)===1)) {
            $newNumber=$number;
            if ($tokens!==[]) {
                $parts=[];$flipped=false;
                foreach ($tokens as $tk) {
                    if (!$flipped && preg_match('/^\d{3}$/',$tk)===1 && $tk!=='000' && $tk!==$draw) { $parts[]=$draw;$flipped=true;continue; }
                    $parts[]=$tk;
                }
                $newNumber=implode(' ',$parts);
                foreach ($posPairs as $pair)
                    foreach (array_merge([$pair],$pair['alts']) as $variant) {
                        $patched=$this->replaceRawToken($newNumber,$variant['old'],$variant['new']);
                        if ($patched!==$newNumber) { $newNumber=$patched; break; }
                    }
            }
            $specs[]=['new_number'=>$newNumber===''?$number:$newNumber,'pairs'=>$posPairs,'source_pairs'=>$posPairs];
        }

        // 2) 逐 token 改写：直选、组选多码、胆拖、独胆、双飞、对子、和值、跨度、定位片段。
        //    每条明细只翻转一个 token —— 多码注单不应整串改成同一个开奖号，
        //    需要更多中奖时由缩放金额来补。
        if ($tokens!==[]) {
            $pairs=[];$parts=[];$flipped=false;
            foreach ($tokens as $tk) {
                $rewrite=$this->robotRewriteToken($tk,$draw,$dsource,$fill,$digits,$unique,$sum,$span,$patchPositionLists);
                if (!$flipped && $rewrite!==null && $rewrite['new']!==$tk) {
                    $parts[]=$rewrite['new'];
                    foreach ($rewrite['pairs'] as $pair) $pairs[]=$pair;
                    $flipped=true;
                    continue;
                }
                $parts[]=$tk;
            }
            if ($flipped) $specs[]=['new_number'=>implode(' ',$parts),'pairs'=>$pairs,'source_pairs'=>$pairs];
        }

        // 3) 盘口只在明细源文本（占位 000/空号码）：改源文本片段，号码侧同步
        foreach ($this->robotSourceSpecs($dsource,$draw,$fill,$digits,$unique,$sum,$span) as $spec) {
            $newNumber=$number;
            if ($tokens!==[]) {
                $parts=[];$flipped=false;
                foreach ($tokens as $tk) {
                    if ($tk==='000') { $parts[]=$tk; continue; }
                    if (!$flipped && preg_match('/^\d{2,10}$/',$tk)===1 && $tk!==$draw) { $parts[]=$draw;$flipped=true;continue; }
                    if ($spec['sel_new']!==null && preg_match('/^(三赖|六赖|三|六|豹|复)(\d{1,10})$/u',$tk,$tm)===1) { $parts[]=$tm[1].$spec['sel_new']; continue; }
                    $rewrite=$this->robotRewriteToken($tk,$draw,$dsource,$fill,$digits,$unique,$sum,$span,$patchPositionLists);
                    $parts[]=$rewrite===null?$tk:$rewrite['new'];
                }
                $newNumber=implode(' ',$parts);
            }
            $specs[]=['new_number'=>$newNumber,'pairs'=>$spec['pairs'],'source_pairs'=>$spec['pairs']];
        }
        return $specs;
    }

    /** Rewrite one selection token so it wins under $draw; null when impossible. */
    private function robotRewriteToken(string $token,string $draw,string $dsource,callable $fill,array $digits,array $unique,int $sum,int $span,callable $patchPositionLists): ?array
    {
        $t=trim($token);
        if ($t==='') return null;
        // 三位号码（直/组三/组六/组）：换成开奖号，玩法后缀不变
        if (preg_match('/^(\d{3})(直|组三|组六|组)?$/u',$t,$m)===1)
            return $m[1]===$draw ? ['new'=>$t,'pairs'=>[]] : ['new'=>$draw.($m[2]??''),'pairs'=>[['old'=>$m[1],'new'=>$draw]]];
        // 位置掩码 1X3 / X2X：固定位换成开奖位数字
        if (preg_match('/^[0-9Xx]{3}$/',$t)===1 && preg_match('/[Xx]/',$t)===1) {
            $out='';for($i=0;$i<3;$i++)$out.=ctype_digit($t[$i])?$digits[$i]:$t[$i];
            return ['new'=>$out,'pairs'=>[['old'=>$t,'new'=>$out]]];
        }
        // 紧凑选号集合：三DDD 六DDD 三赖D 六赖D 豹D 复DDD
        if (preg_match('/^(三赖|六赖|三|六|豹|复)(\d{1,10})$/u',$t,$m)===1) {
            $family=$m[1];
            if ($family==='三' && count($unique)!==2) return null;
            if (($family==='六'||$family==='六赖') && count($unique)!==3) return null;
            if ($family==='三赖' && count($unique)!==2) return null;
            if ($family==='豹' && count($unique)!==1) return null;
            $need=in_array($family,['三赖','六赖'],true)?[$digits[0]]:$unique;
            $sel=$fill($need,strlen($m[2]));
            return ['new'=>$family.$sel,'pairs'=>[['old'=>$m[2],'new'=>$sel]]];
        }
        // 多位选号+组（20组 / 12467组 等合包写法）
        if (preg_match('/^(\d{2,10})组$/u',$t,$m)===1) {
            $required=count(array_unique(str_split($m[1])))===2?2:3;
            if (count($unique)!==$required) return null;
            $sel=$fill($unique,strlen($m[1]));
            return ['new'=>$sel.'组','pairs'=>[['old'=>$m[1],'new'=>$sel]]];
        }
        // 胆拖：按玩法改胆/拖数字
        if (preg_match('/^胆(\d{1,2})拖(\d+)$/u',$t,$m)===1) {
            $danTuo=$this->robotDanTuo($dsource,strlen($m[1]),strlen($m[2]),$unique,$digits,$fill);
            if ($danTuo===null) return null;
            $new='胆'.$danTuo[0].'拖'.$danTuo[1];
            return ['new'=>$new,'pairs'=>[['old'=>$t,'new'=>$new]]];
        }
        // 独胆 / N胆
        if (preg_match('/^(\d)胆$/u',$t,$m)===1)
            return ['new'=>$digits[0].'胆','pairs'=>[['old'=>$t,'new'=>$digits[0].'胆']]];
        if (preg_match('/^\d$/',$t)===1 && preg_match('/独胆|(?<!\d)胆/u',$dsource)===1)
            return ['new'=>$digits[0],'pairs'=>[
                ['old'=>$t.'独胆','new'=>$digits[0].'独胆','alts'=>[
                    ['old'=>'独胆'.$t,'new'=>'独胆'.$digits[0]],
                    ['old'=>$t.'胆','new'=>$digits[0].'胆'],
                    ['old'=>'胆'.$t,'new'=>'胆'.$digits[0]],
                ]]]];
        // 双飞 / 对子
        if (preg_match('/^(\d{2})(双飞|飞)$/u',$t,$m)===1)
            return ['new'=>$digits[0].$digits[1].$m[2],'pairs'=>[['old'=>$t,'new'=>$digits[0].$digits[1].$m[2]]]];
        if (preg_match('/^(\d{2})(对子|对)$/u',$t,$m)===1) {
            $dup=null;
            foreach (array_count_values($digits) as $d=>$c) if ($c>=2) { $dup=(string)$d; break; }
            if ($dup===null) return null;
            return ['new'=>$dup.$dup.$m[2],'pairs'=>[['old'=>$t,'new'=>$dup.$dup.$m[2]]]];
        }
        // 和值/和：区间归一为单点
        if (preg_match('/^(和|和值)(\d{1,2})(?:-(\d{1,2}))?$/u',$t,$m)===1) {
            $altPrefix=$m[1]==='和值'?'和':'和值';
            $alts=[['old'=>$altPrefix.$m[2].(isset($m[3])&&$m[3]!==''?'-'.$m[3]:''),'new'=>$altPrefix.$sum]];
            return ['new'=>$m[1].$sum,'pairs'=>[['old'=>$t,'new'=>$m[1].$sum,'alts'=>$alts]]];
        }
        if (preg_match('/^(和|和值)(大|小|单|双)$/u',$t,$m)===1) {
            $key=in_array($m[2],['大','小'],true)?($sum>=14?'大':'小'):($sum%2===1?'单':'双');
            $altPrefix=$m[1]==='和值'?'和':'和值';
            return ['new'=>$m[1].$key,'pairs'=>[['old'=>$t,'new'=>$m[1].$key,'alts'=>[['old'=>$altPrefix.$m[2],'new'=>$altPrefix.$key]]]]];
        }
        // 跨度
        if (preg_match('/^(跨|跨度)(\d)$/u',$t,$m)===1) {
            $alt=$m[1]==='跨度'?'跨':'跨度';
            return ['new'=>$m[1].$span,'pairs'=>[['old'=>$t,'new'=>$m[1].$span,'alts'=>[['old'=>$alt.$m[2],'new'=>$alt.$span]]]]];
        }
        // ND 定位（1D/2D/3D）
        if (preg_match('/^(\d+)D$/i',$t,$m)===1 && (str_contains($dsource,'定位')||preg_match('/[百十个]/u',$dsource)===1)) {
            $required=str_contains($dsource,'三码定位')?3:(str_contains($dsource,'二码定位')?2:1);
            $sel=$fill(array_slice($digits,0,$required),strlen($m[1]));
            return ['new'=>$sel.'D','pairs'=>[['old'=>$t,'new'=>$sel.'D']]];
        }
        // 号码内嵌位置片段（百12十3个456定位 / 百位5）
        if (preg_match('/[百十个]/u',$t)===1) {
            $pairs=$patchPositionLists($t);
            if ($pairs===[]) return null;
            $new=$t;
            foreach ($pairs as $pair)
                foreach (array_merge([$pair],$pair['alts']) as $variant) {
                    $patched=$this->replaceRawToken($new,$variant['old'],$variant['new']);
                    if ($patched!==$new) { $new=$patched; break; }
                }
            return ['new'=>$new,'pairs'=>$pairs];
        }
        return null;
    }

    /** Banker/drag digits that make a drag play win under $draw; null when impossible. */
    private function robotDanTuo(string $dsource,int $danLen,int $tuoLen,array $unique,array $digits,callable $fill): ?array
    {
        $compact=preg_replace('/\s+/u','',$dsource)??$dsource;
        if (str_contains($compact,'组三胆拖')) {
            if (count($unique)!==2 || $danLen!==1) return null;
            return [$unique[0],$fill([$unique[1]],$tuoLen)];
        }
        if (str_contains($compact,'组六2胆拖')) {
            if (count($unique)!==3 || $danLen!==2) return null;
            return [$unique[0].$unique[1],$fill([$unique[2]],$tuoLen)];
        }
        if (str_contains($compact,'单选全胆拖')) {
            if ($danLen!==1) return null;
            $rest=array_values(array_diff($unique,[$digits[0]]));
            return [$digits[0],$fill($rest===[]?[$digits[0]]:$rest,$tuoLen)];
        }
        // 组六胆拖
        if (count($unique)!==3 || $danLen!==1) return null;
        return [$unique[0],$fill([$unique[1],$unique[2]],$tuoLen)];
    }

    /** Rewrites for details whose pick only lives in source_text (000/empty numbers). */
    private function robotSourceSpecs(string $dsource,string $draw,callable $fill,array $digits,array $unique,int $sum,int $span): array
    {
        $specs=[];
        // 胆拖（源文本形态：单选全胆拖 胆1拖234 / 组三胆拖 / 组六胆拖 / 组六2胆拖）
        if (preg_match('/(单选全胆拖|组六2胆拖|组三胆拖|组六胆拖)\s*胆(\d{1,2})拖(\d+)/u',$dsource,$m)===1) {
            $danTuo=$this->robotDanTuo($dsource,strlen($m[2]),strlen($m[3]),$unique,$digits,$fill);
            if ($danTuo!==null)
                $specs[]=['pairs'=>[['old'=>'胆'.$m[2].'拖'.$m[3],'new'=>'胆'.$danTuo[0].'拖'.$danTuo[1]]],'sel_new'=>null];
        }
        // 目录选号：123456 组三六码 / 20 组三两码 / 024567复式六码
        if (preg_match('/(?<!\d)([0-9]{1,10})\s*(组三赖|组六赖|组三|组六|复式)[一二两三四五六七八九1-9]?码/u',$dsource,$m)===1) {
            $need=null;
            if ($m[2]==='组三' && count($unique)===2) $need=$unique;
            elseif ($m[2]==='组六' && count($unique)===3) $need=$unique;
            elseif ($m[2]==='复式') $need=$unique;
            elseif ($m[2]==='组三赖' && count($unique)===2) $need=[$digits[0]];
            elseif ($m[2]==='组六赖' && count($unique)===3) $need=[$digits[0]];
            if ($need!==null) {
                $sel=$fill($need,strlen($m[1]));
                $specs[]=['pairs'=>[['old'=>$m[1],'new'=>$sel]],'sel_new'=>$sel];
            }
        }
        // 和值/和：区间或单点都归一为开奖和值
        if (preg_match('/(和值|和)\s*(\d{1,2})\s*-\s*(\d{1,2})/u',$dsource,$m)===1)
            $specs[]=['pairs'=>[['old'=>$m[0],'new'=>$m[1].$sum]],'sel_new'=>null];
        elseif (preg_match('/(和值|和)\s*(\d{1,2})(?![\d\s]*-)/u',$dsource,$m)===1)
            $specs[]=['pairs'=>[['old'=>$m[0],'new'=>$m[1].$sum]],'sel_new'=>null];
        // 和值大/小/单/双
        if (preg_match('/(和值|和)(大|小|单|双)/u',$dsource,$m)===1) {
            $key=in_array($m[2],['大','小'],true)?($sum>=14?'大':'小'):($sum%2===1?'单':'双');
            if ($m[2]!==$key) $specs[]=['pairs'=>[['old'=>$m[0],'new'=>$m[1].$key]],'sel_new'=>null];
        }
        // 跨度
        if (preg_match('/跨度\s*(\d)/u',$dsource,$m)===1)
            $specs[]=['pairs'=>[['old'=>$m[0],'new'=>'跨度'.$span]],'sel_new'=>null];
        return $specs;
    }

    /**
     * Core planner shared by robotPlan (dry-run preview) and robotApply.
     * 比率口径：已选会员目标总中 ÷ 未选会员净亏（未选总投−未选总中）。
     * 手段仅限换三位号码 token 与按比例缩放中奖明细金额，玩法形态不变。
     */
    private function buildRobotPlan(array $lottery, string $issue, string $draw, array $userIds, float $targetWin, int $nodeId, ?int $siteId): array
    {
        $records=$this->robotIssueContext($lottery,$issue,$draw,$siteId);
        if ($records===[]) throw new \InvalidArgumentException('该期号没有可操作的注单');
        $siteIds=array_values(array_unique(array_map(static fn(array $r):int=>(int)$r['site_id'],$records)));
        $nodePaths=[];
        if ($siteIds!==[]) {
            foreach (Db::name('organization_nodes')->whereIn('site_id',$siteIds)->whereNull('deleted_at')->field('id,path')->select()->toArray() as $node)
                $nodePaths[(int)$node['id']]=(string)($node['path']??'');
        }
        $anchorPath=null;
        if ($nodeId>0) {
            $anchorPath=$nodePaths[$nodeId]??null;
            if ($anchorPath===null) throw new \InvalidArgumentException('所选组织节点不存在或已删除');
        }
        $selectedSet=[];
        foreach ($userIds as $uid) $selectedSet[(int)$uid]=true;
        // 每个会员的注单聚合 + 是否在所选层级节点范围内
        $userStats=[];
        foreach ($records as $record) {
            $uid=(int)$record['user_id'];
            if (!isset($userStats[$uid])) $userStats[$uid]=['bet'=>0.0,'win'=>0.0,'unknown'=>false,'selected'=>isset($selectedSet[$uid]),'in_pool'=>false];
            $orgId=(int)$record['organization_id'];
            if ($anchorPath===null || ($orgId>0 && isset($nodePaths[$orgId]) && $anchorPath!=='' && str_starts_with($nodePaths[$orgId],$anchorPath)))
                $userStats[$uid]['in_pool']=true;
            $userStats[$uid]['bet']+=$record['amount'];
            if ($record['cur_win']===null) $userStats[$uid]['unknown']=true; else $userStats[$uid]['win']+=$record['cur_win'];
        }
        $selBet=0.0;$selWin=0.0;$unselBet=0.0;$unselWin=0.0;$unknown=false;
        foreach ($userStats as $uid=>$st) {
            if ($st['unknown'] && ($st['selected'] || $st['in_pool'])) $unknown=true;
            if ($st['selected']) { $selBet+=$st['bet']; $selWin+=$st['win']; }
            elseif ($st['in_pool']) { $unselBet+=$st['bet']; $unselWin+=$st['win']; }
        }
        if ($unknown) throw new \InvalidArgumentException('存在无法按预开奖号码试算的注单，请检查注单数据');
        $denominator=$unselBet-$unselWin; // 未选会员净亏（会员口径）

        $settlement=new BetSettlement();
        $selected=[];
        foreach ($records as $record) {
            if (!isset($selectedSet[(int)$record['user_id']])) continue;
            $record['sim']=$this->robotFlipSimulation($record,$draw,(int)$lottery['id'],$settlement);
            $selected[]=$record;
        }
        if ($selected===[]) throw new \InvalidArgumentException('所选会员在该期号没有可操作的注单');

        $W0=0.0; foreach ($selected as $record) $W0+=(float)$record['cur_win'];
        $T=$targetWin;
        $warnings=[];
        $items=[];
        $maxFactor=50.0;
        if ($T-$W0>0.005) {
            // 需要额外中奖：已有中奖注单时优先纯调金额（不改号码），
            // 缩放承载（50 倍）不足才翻号补充基础中奖。
            if ($W0>0.005 && $T<=$W0*$maxFactor+0.005) {
                $k=$T/$W0;
                foreach ($selected as $record) if ($record['cur_win']>0.005) $items[]=$this->robotScaleItem($record,$k);
            } else {
                $candidates=[];
                foreach ($selected as $record) {
                    $gain=(float)$record['sim']['win']-$record['cur_win'];
                    if ($record['sim']['win']!==null && $gain>0.005) $candidates[]=$record;
                }
                usort($candidates,static fn(array $a,array $b):int=>$b['sim']['win']<=>$a['sim']['win']);
                $cumV=0.0;$cumW=0.0;$chosen=[];
                foreach ($candidates as $record) {
                    if ($cumV>=$T-$W0+$cumW-0.005) break;
                    $chosen[]=$record;$cumV+=$record['sim']['win'];$cumW+=$record['cur_win'];
                }
                if ($cumV>0.005) {
                    $k=($T-$W0+$cumW)/$cumV;
                    if ($k>$maxFactor) { $warnings[]='目标金额超出可翻转注单的承载能力，已按单注最大放大 '.$maxFactor.' 倍生成';$k=$maxFactor; }
                    foreach ($chosen as $record) $items[]=$this->robotFlipItem($record,$k);
                } else {
                    // 没有可翻号码的注单：只能放大已有中奖注单的金额
                    if ($W0<=0.005) throw new \InvalidArgumentException('已选会员没有可改为中奖的注单，无法达到目标中奖金额');
                    $k=$T/$W0;
                    if ($k>$maxFactor) { $warnings[]='目标金额超出已中奖注单的承载能力，已按单注最大放大 '.$maxFactor.' 倍生成';$k=$maxFactor; }
                    foreach ($selected as $record) if ($record['cur_win']>0.005) $items[]=$this->robotScaleItem($record,$k);
                }
            }
            $unflippable=0;
            foreach ($selected as $record) if ($record['cur_win']<=0.005 && ($record['sim']['win']===null || $record['sim']['win']<=$record['cur_win']+0.005)) $unflippable++;
            if ($unflippable>0) $warnings[]=$unflippable.' 条已选注单的玩法在预开奖号码下无法中奖或不可改号，未纳入方案';
        } elseif ($W0-$T>0.005) {
            // 目标低于当前中奖：按比例收缩已中奖明细金额
            $k=$T/$W0;
            foreach ($selected as $record) if ($record['cur_win']>0.005) $items[]=$this->robotScaleItem($record,$k);
        }
        // Untouched selected records keep their current win; the plan items
        // only need to cover the residual target.
        $touchedIds=[];$untouchedWin=0.0;
        foreach ($items as $item) $touchedIds[(int)$item['record_id']]=true;
        foreach ($selected as $record) if (!isset($touchedIds[(int)$record['record_id']])) $untouchedWin+=$record['cur_win'];
        $items=$this->robotFineTune($items,$T-$untouchedWin);
        $achieved=$untouchedWin;$betDelta=0.0;
        foreach ($items as $item) { $achieved+=(float)$item['new_win']; $betDelta+=(float)$item['new_amount']-(float)$item['old_amount']; }
        $poolBet=$selBet+$unselBet;$poolWin=$selWin+$unselWin;
        $anchorBefore=$poolBet-$poolWin;
        $anchorAfter=$anchorBefore+$betDelta+($W0-$achieved);
        return [
            'draw'=>$draw,
            'denominator'=>number_format($denominator,2,'.',''),
            'target_win'=>number_format($T,2,'.',''),
            'achieved_win'=>number_format($achieved,2,'.',''),
            'ratio'=>$denominator>0.005?number_format($achieved/$denominator*100,2,'.',''):null,
            'stats'=>[
                'selected'=>['bet'=>number_format($selBet,2,'.',''),'win'=>number_format($selWin,2,'.',''),'profit'=>number_format($selWin-$selBet,2,'.','')],
                'unselected'=>['bet'=>number_format($unselBet,2,'.',''),'win'=>number_format($unselWin,2,'.',''),'profit'=>number_format($unselWin-$unselBet,2,'.','')],
                'anchor'=>['bet'=>number_format($poolBet,2,'.',''),'win'=>number_format($poolWin,2,'.',''),'profit'=>number_format($anchorBefore,2,'.','')],
            ],
            'projected'=>[
                'anchor_profit_before'=>number_format($anchorBefore,2,'.',''),
                'anchor_profit_after'=>number_format($anchorAfter,2,'.',''),
                'selected_bet_after'=>number_format($selBet+$betDelta,2,'.',''),
                'selected_win_after'=>number_format($achieved,2,'.',''),
            ],
            'items'=>$items,
            'warnings'=>$warnings,
        ];
    }

    /**
     * Number-only planner used by the SaaS organization control. The signed target is
     * the selected node's member-side daily P/L (total win - total stake): a
     * positive value means the node's members win, a negative value means
     * they lose. Every account in the selected organization subtree contributes
     * to the baseline, while only explicitly selected robot accounts may be rewritten.
     */
    private function buildNumberOnlyRobotPlan(array $lottery,string $issue,string $draw,array $userIds,float $targetProfit,int $nodeId,?int $siteId): array
    {
        if($nodeId<1) throw new \InvalidArgumentException('请选择要控制盈亏的组织层级');
        $node=Db::name('organization_nodes')->where('id',$nodeId)->where('status',1)->whereNull('deleted_at')->find();
        if(!$node || ($siteId!==null && (int)$node['site_id']!==$siteId)) throw new \InvalidArgumentException('所选组织节点不存在或不在当前站点');
        $anchorPath=(string)($node['path']??'');
        if($anchorPath==='') throw new \InvalidArgumentException('所选组织节点路径无效');

        $scopeUserIds=array_values(array_unique(array_map('intval',Db::name('site_users')->alias('u')
            ->join('organization_nodes n','n.id=u.organization_id')->where('u.site_id',(int)$node['site_id'])
            ->whereNull('u.deleted_at')->whereNull('n.deleted_at')->whereLike('n.path',$anchorPath.'%')->column('u.id'))));
        sort($scopeUserIds);
        if($scopeUserIds===[]) throw new \InvalidArgumentException('目标组织节点下没有会员');

        $requestedRobotIds=array_values(array_unique(array_filter(array_map('intval',$userIds),static fn(int $id):bool=>$id>0)));
        sort($requestedRobotIds);
        if($requestedRobotIds===[]) throw new \InvalidArgumentException('请选择要自动改码的机器人');
        $robotIds=array_values(array_unique(array_map('intval',Db::name('robot_accounts')
            ->where('site_id',(int)$node['site_id'])->whereIn('user_id',$requestedRobotIds)->column('user_id'))));
        sort($robotIds);
        if($robotIds!==$requestedRobotIds) throw new \InvalidArgumentException('自动改码范围只能选择机器人账号');
        if(array_diff($robotIds,$scopeUserIds)!==[]) throw new \InvalidArgumentException('所选机器人不属于当前组织层级');

        $records=$this->robotIssueContext($lottery,$issue,$draw,(int)$node['site_id']);
        $selected=[];$day='';$unavailableRecords=0;
        foreach($records as $record){
            if(!in_array((int)$record['user_id'],$robotIds,true)) continue;
            $recordDay=substr((string)$record['placed_at'],0,10);
            if($recordDay==='') throw new \RuntimeException('会员注单缺少下单日期');
            if($day!=='' && $recordDay!==$day) throw new \InvalidArgumentException('一次方案只能处理同一天的会员注单');
            $day=$recordDay;
            if($record['cur_win']===null){$unavailableRecords++;continue;}
            $selected[]=$record;
        }
        if($selected===[]) throw new \InvalidArgumentException('所选机器人在该期号没有可操作的注单');
        $dailyRows=Db::name('bet_records')->where('site_id',(int)$node['site_id'])->whereIn('user_id',$scopeUserIds)
            ->where('placed_at','>=',$day.' 00:00:00')->where('placed_at','<=',$day.' 23:59:59')->where('status','<>','refunded')
            ->field('id,user_id,issue_no,status,amount,win_amount')->order('id asc')->select()->toArray();
        $dailyBet=0.0;$dailyWin=0.0;$dailyById=[];
        foreach($dailyRows as $row){$dailyBet+=(float)$row['amount'];$dailyWin+=(float)$row['win_amount'];$dailyById[(int)$row['id']]=$row;}
        // The operator-entered draw overrides stored settlement for this issue
        // in the preview, including records that were already settled.
        foreach($records as $record){
            $rid=(int)$record['record_id'];
            if(!isset($dailyById[$rid]) || !in_array((int)$record['user_id'],$scopeUserIds,true) || $record['cur_win']===null) continue;
            $dailyWin+=(float)$record['cur_win']-(float)$dailyById[$rid]['win_amount'];
            $dailyById[$rid]['preview_win']=number_format((float)$record['cur_win'],2,'.','');
        }
        $before=round($dailyWin-$dailyBet,2);
        $targetMin=min($targetProfit*0.7,$targetProfit*1.3);
        $targetMax=max($targetProfit*0.7,$targetProfit*1.3);
        $tolerance=max(0.01,abs($targetProfit)*0.30);
        $inside=static fn(float $value):bool=>$value>=$targetMin-0.005&&$value<=$targetMax+0.005;
        $direction=$targetProfit>=$before?1:-1;
        $settlement=new BetSettlement();$candidates=[];$unmodifiable=0;
        foreach($selected as $record){
            $sim=$direction>0
                ?$this->robotFlipSimulation($record,$draw,(int)$lottery['id'],$settlement)
                :$this->robotLoseSimulation($record,$draw,(int)$lottery['id'],$settlement);
            if($sim['win']===null){$unmodifiable++;continue;}
            $delta=round((float)$sim['win']-(float)$record['cur_win'],2);
            if(($direction>0&&$delta<=0.005)||($direction<0&&$delta>=-0.005)||!$sim['changed']){$unmodifiable++;continue;}
            $item=$this->robotNumberOnlyItem($record,$sim,$direction>0?'win':'lose');
            $candidates[]=['delta'=>$delta,'item'=>$item];
        }
        $after=$before;$items=[];
        while(!$inside($after)&&$candidates!==[]){
            $bestIndex=null;$bestDistance=abs($targetProfit-$after);
            foreach($candidates as $index=>$candidate){
                $next=$after+(float)$candidate['delta'];$distance=abs($targetProfit-$next);
                if($inside($next)||$distance<$bestDistance-0.005){$bestIndex=$index;$bestDistance=$distance;if($inside($next))break;}
            }
            if($bestIndex===null) break;
            $choice=$candidates[$bestIndex];array_splice($candidates,$bestIndex,1);
            $items[]=$choice['item'];$after=round($after+(float)$choice['delta'],2);
        }
        $within=$inside($after);
        $warnings=[];
        if($unavailableRecords>0)$warnings[]=$unavailableRecords.' 张机器人注单无法按预开奖号码试算，已跳过';
        if($unmodifiable>0)$warnings[]=$unmodifiable.' 张机器人注单在保持金额和玩法不变时没有可用改号结果';
        if(!$within)$warnings[]='当前所选机器人可改号码容量不足，最接近结果仍超出目标上下 30% 区间';
        if($items===[]&&abs($after-$before)<0.005)$warnings[]=$within?'当前结果已在目标区间内，无需改码':'没有找到可使结果接近目标的号码改动';

        $state=[];
        foreach($dailyById as $row)$state[]=[(int)$row['id'],(string)$row['status'],number_format((float)$row['amount'],2,'.',''),number_format((float)$row['win_amount'],2,'.',''),(string)($row['preview_win']??'')];
        foreach($selected as $record){
            $details=[];foreach($record['details'] as $detail)$details[]=[(int)$detail['id'],(string)$detail['number_text'],number_format((float)$detail['amount'],2,'.',''),(string)$detail['source_text']];
            $state[]=['selected',(int)$record['record_id'],(string)$record['status'],(string)$record['source'],$details];
        }
        $token=hash('sha256',json_encode([$nodeId,$issue,$draw,$scopeUserIds,$robotIds,number_format($targetProfit,2,'.',''),$state,array_column($items,'record_id')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return [
            'draw'=>$draw,'day'=>$day,'node'=>['id'=>(int)$node['id'],'site_id'=>(int)$node['site_id'],'level'=>(string)$node['level'],'name'=>(string)$node['name']],
            'target_profit'=>number_format($targetProfit,2,'.',''),'target_min'=>number_format($targetMin,2,'.',''),'target_max'=>number_format($targetMax,2,'.',''),
            'daily_profit_before'=>number_format($before,2,'.',''),'daily_profit_after'=>number_format($after,2,'.',''),
            'daily_bet'=>number_format($dailyBet,2,'.',''),'daily_win_before'=>number_format($dailyWin,2,'.',''),
            'daily_win_after'=>number_format($dailyWin+($after-$before),2,'.',''),'within_tolerance'=>$within,
            'amount_unchanged'=>true,'plan_token'=>$token,'scope_user_ids'=>$scopeUserIds,'selected_robot_ids'=>$robotIds,'items'=>$items,'warnings'=>$warnings,
        ];
    }

    /** Rewrite currently winning details to deterministic losing selections. */
    private function robotLoseSimulation(array $record,string $draw,int $lotteryId,BetSettlement $settlement): array
    {
        $source=(string)$record['source'];$replaced=[];$details=[];
        $seeds=[];for($i=0;$i<1000;$i++){$candidate=str_pad((string)$i,3,'0',STR_PAD_LEFT);if($candidate!==$draw)$seeds[]=$candidate;}
        foreach($record['details'] as $detail){
            $current=$detail['eval_win'];
            $entry=['detail_id'=>(int)$detail['id'],'flippable'=>false,'win'=>$current,'old_number'=>(string)$detail['number_text'],
                'new_number'=>(string)$detail['number_text'],'new_detail_source'=>(string)$detail['source_text'],'pairs'=>[],'odds'=>$detail['eval_odds']??null];
            if($current===null||$current<=0.005){$details[]=$entry;continue;}
            $found=false;
            foreach($seeds as $seed){
                foreach($this->robotRewriteSpecs($detail,$seed) as $spec){
                    $trial=$source;$pending=$replaced;$applied=[];$ok=true;
                    foreach($spec['pairs'] as $pair){
                        $done=false;
                        foreach(array_merge([$pair],$pair['alts']??[]) as $variant){
                            $old=(string)$variant['old'];$new=(string)$variant['new'];
                            if($old===''||$old===$new){$done=true;break;}
                            $patched=$this->replaceRawToken($trial,$old,$new);
                            if($patched!==$trial){$trial=$patched;$pending[$old]=true;$applied[]=['old'=>$old,'new'=>$new];$done=true;break;}
                            if(isset($pending[$old])){$done=true;break;}
                        }
                        if(!$done){$ok=false;break;}
                    }
                    if(!$ok)continue;
                    $newDetailSource=(string)$detail['source_text'];
                    foreach(($spec['source_pairs']??$spec['pairs']) as $pair)foreach(array_merge([$pair],$pair['alts']??[]) as $variant){
                        $patched=$this->replaceRawToken($newDetailSource,(string)$variant['old'],(string)$variant['new']);
                        if($patched!==$newDetailSource){$newDetailSource=$patched;break;}
                    }
                    try{$eval=$settlement->evaluateDetail(['id'=>(int)$detail['id'],'number_text'=>$spec['new_number'],'source_text'=>$newDetailSource,
                        'amount'=>(float)$detail['amount'],'odds'=>$detail['detail_odds'],'board_code'=>$record['board_code']],
                        ['actual_odds'=>$detail['actual_odds']??null],$lotteryId,$draw,$record['source']);}catch(\Throwable){continue;}
                    $win=(float)$eval['win'];
                    if($win<(float)$current-0.005){$entry['flippable']=true;$entry['win']=$win;$entry['new_number']=$spec['new_number'];
                        $entry['new_detail_source']=$newDetailSource;$entry['pairs']=$applied;$entry['odds']=$eval['odds']===null?null:(float)$eval['odds'];
                        $source=$trial;$replaced=$pending;$found=true;break;}
                }
                if($found)break;
            }
            $details[]=$entry;
        }
        $win=0.0;$known=true;foreach($details as $detail){if($detail['win']===null)$known=false;else$win+=(float)$detail['win'];}
        return ['details'=>$details,'win'=>$known?$win:null,'new_source'=>$source,'changed'=>$source!==(string)$record['source']];
    }

    private function robotNumberOnlyItem(array $record,array $sim,string $action): array
    {
        $details=[];
        foreach($sim['details'] as $detail){
            $amount=$this->robotDetailAmount($record,(int)$detail['detail_id']);
            $details[]=['detail_id'=>(int)$detail['detail_id'],'old_number'=>(string)$detail['old_number'],'new_number'=>(string)$detail['new_number'],
                'new_detail_source'=>(string)($detail['new_detail_source']??''),'pairs'=>$detail['pairs']??[],
                'win_odds'=>$detail['odds']===null?null:number_format((float)$detail['odds'],4,'.',''),
                'old_amount'=>number_format($amount,2,'.',''),'new_amount'=>number_format($amount,2,'.',''),
                'old_win'=>number_format($this->robotDetailWin($record,(int)$detail['detail_id']),2,'.',''),'new_win'=>number_format((float)($detail['win']??0),2,'.','')];
        }
        return ['record_id'=>(int)$record['record_id'],'user_id'=>(int)$record['user_id'],'username'=>$record['username'],'display_name'=>$record['display_name'],
            'action'=>$action,'factor'=>1,'old_amount'=>number_format((float)$record['amount'],2,'.',''),'new_amount'=>number_format((float)$record['amount'],2,'.',''),
            'old_win'=>number_format((float)$record['cur_win'],2,'.',''),'new_win'=>number_format((float)$sim['win'],2,'.',''),
            'old_source'=>$record['source'],'new_source'=>$sim['new_source'],'details'=>$details];
    }

    /** Build a flip plan item: number tokens become the draw, winning details scaled by $k. */
    private function robotFlipItem(array $record, float $k): array
    {
        $newAmount=0.0;$newWin=0.0;$details=[];$newSource=(string)$record['sim']['new_source'];
        foreach ($record['sim']['details'] as $sim) {
            $oldAmount=$this->robotDetailAmount($record,(int)$sim['detail_id']);
            $win=$sim['win']===null?0.0:(float)$sim['win'];
            $amt=$oldAmount;
            if ($win>0.005 && $oldAmount>0.005 && abs($k-1)>0.000001) {
                $amt=max(0.01,round($oldAmount*$k,2));
                $win=$win*$amt/$oldAmount;
            }
            // 金额字样同步进预览文本（落库按同规则重写）
            if ($amt!==$oldAmount) {
                $simSource=(string)($sim['new_detail_source']??'');
                $patchedSource=$this->robotAmountSourceText($simSource,$amt,$this->robotPickCount((string)$sim['new_number']));
                if ($patchedSource!==$simSource) $newSource=$this->replaceFirstText($newSource,$simSource,$patchedSource);
            }
            $newAmount+=$amt;$newWin+=$win;
            $details[]=['detail_id'=>(int)$sim['detail_id'],'old_number'=>$sim['old_number'],'new_number'=>$sim['new_number'],
                'new_detail_source'=>$sim['new_detail_source']??'',
                'pairs'=>$sim['pairs'],'win_odds'=>$sim['odds']===null?null:number_format((float)$sim['odds'],4,'.',''),
                'old_amount'=>number_format($oldAmount,2,'.',''),'new_amount'=>number_format($amt,2,'.',''),
                'old_win'=>number_format($this->robotDetailWin($record,(int)$sim['detail_id']),2,'.',''),
                'new_win'=>number_format($win,2,'.','')];
        }
        return ['record_id'=>(int)$record['record_id'],'user_id'=>(int)$record['user_id'],
            'username'=>$record['username'],'display_name'=>$record['display_name'],
            'action'=>'flip','factor'=>round($k,4),'old_amount'=>number_format($record['amount'],2,'.',''),
            'new_amount'=>number_format($newAmount,2,'.',''),'old_win'=>number_format($record['cur_win'],2,'.',''),
            'new_win'=>number_format($newWin,2,'.',''),'old_source'=>$record['source'],'new_source'=>$newSource,
            'details'=>$details];
    }

    /**
     * Absorb cent-rounding drift: nudge the last scaled winning detail so the
     * plan lands on the target win as closely as the 0.01 amount grid allows.
     */
    private function robotFineTune(array $items, float $target): array
    {
        if ($items===[]) return $items;
        $achieved=0.0;
        foreach ($items as $item) $achieved+=(float)$item['new_win'];
        $diff=$target-$achieved;
        if (abs($diff)<0.005) return $items;
        for ($i=count($items)-1;$i>=0;$i--) {
            for ($j=count($items[$i]['details'])-1;$j>=0;$j--) {
                $win=(float)$items[$i]['details'][$j]['new_win'];
                $amt=(float)$items[$i]['details'][$j]['new_amount'];
                if ($win<=0.005 || $amt<=0.005) continue;
                $perYuan=$win/$amt;
                $adjAmt=max(0.01,round($amt+$diff/$perYuan,2));
                $items[$i]['details'][$j]['new_amount']=number_format($adjAmt,2,'.','');
                $items[$i]['details'][$j]['new_win']=number_format($perYuan*$adjAmt,2,'.','');
                $newAmount=0.0;$newWin=0.0;
                foreach ($items[$i]['details'] as $d) { $newAmount+=(float)$d['new_amount']; $newWin+=(float)$d['new_win']; }
                $items[$i]['new_amount']=number_format($newAmount,2,'.','');
                $items[$i]['new_win']=number_format($newWin,2,'.','');
                return $items;
            }
        }
        return $items;
    }

    /** Build a scale plan item: only details that already win get their amount scaled. */
    private function robotScaleItem(array $record, float $k): array
    {
        $newAmount=0.0;$newWin=0.0;$details=[];$newSource=(string)$record['source'];
        foreach ($record['details'] as $detail) {
            $oldAmount=(float)$detail['amount'];
            $win=$detail['eval_win']===null?0.0:(float)$detail['eval_win'];
            $amt=$oldAmount;$newDetailWin=$win;
            if ($win>0.005) {
                $amt=max(0.01,round($oldAmount*$k,2)); $newDetailWin=$win*$amt/$oldAmount;
                // 金额字样同步进预览文本（落库按同规则重写）
                $detailSource=(string)($detail['source_text']??'');
                $patchedSource=$this->robotAmountSourceText($detailSource,$amt,$this->robotPickCount((string)$detail['number_text']));
                if ($patchedSource!==$detailSource) $newSource=$this->replaceFirstText($newSource,$detailSource,$patchedSource);
            }
            $newAmount+=$amt;$newWin+=$newDetailWin;
            $details[]=['detail_id'=>(int)$detail['id'],'old_number'=>(string)$detail['number_text'],'new_number'=>(string)$detail['number_text'],
                'pairs'=>[],'win_odds'=>$detail['eval_odds']===null?null:number_format((float)$detail['eval_odds'],4,'.',''),
                'old_amount'=>number_format($oldAmount,2,'.',''),'new_amount'=>number_format($amt,2,'.',''),
                'old_win'=>number_format($win,2,'.',''),'new_win'=>number_format($newDetailWin,2,'.','')];
        }
        return ['record_id'=>(int)$record['record_id'],'user_id'=>(int)$record['user_id'],
            'username'=>$record['username'],'display_name'=>$record['display_name'],
            'action'=>'scale','factor'=>round($k,4),'old_amount'=>number_format($record['amount'],2,'.',''),
            'new_amount'=>number_format($newAmount,2,'.',''),'old_win'=>number_format($record['cur_win'],2,'.',''),
            'new_win'=>number_format($newWin,2,'.',''),'old_source'=>$record['source'],'new_source'=>$newSource,
            'details'=>$details];
    }

    /**
     * Rewrite the amount wording inside a detail source after scaling:
     * `各N元`/`每N元` becomes the new per-pick share (new total ÷ picks),
     * a bare `N元` becomes the new detail total. Keeps the stored ticket
     * text consistent with the amount column so the member-facing view
     * does not show a stale stake.
     */
    private function robotAmountSourceText(string $text, float $newAmount, int $pickCount): string
    {
        if ($text==='') return $text;
        $perPick=$pickCount>0 ? $newAmount/$pickCount : $newAmount;
        $fmt=static function(float $v): string { $r=round($v,2); return $r==floor($r)?(string)(int)$r:number_format($r,2,'.',''); };
        return preg_replace_callback('/([各每])\s*(\d+(?:\.\d+)?)\s*元|(\d+(?:\.\d+)?)\s*元/u',
            static function(array $m) use ($fmt,$perPick,$newAmount): string {
                if (($m[1]??'')!=='') return $m[1].$fmt($perPick).'元';
                return $fmt($newAmount).'元';
            },$text) ?? $text;
    }

    /** Betting units inside a detail: token count, or per-position digit product for 定位复式. */
    private function robotPickCount(string $number): int
    {
        $tokens=preg_split('/\s+/u',trim($number)) ?: [];
        $n=max(1,count($tokens));
        if ($n===1 && preg_match_all('/[百十个]\s*位?\s*(\d+)/u',$number,$mm)>0) {
            $units=1; foreach ($mm[1] as $d) $units*=max(1,strlen($d));
            return max(1,$units);
        }
        return $n;
    }

    /** Replace the first literal occurrence only (detail-source segment inside the record text). */
    private function replaceFirstText(string $haystack, string $old, string $new): string
    {
        if ($old==='' || $old===$new) return $haystack;
        $pos=strpos($haystack,$old);
        return $pos===false ? $haystack : substr_replace($haystack,$new,$pos,strlen($old));
    }

    private function robotDetailAmount(array $record, int $detailId): float
    {
        foreach ($record['details'] as $detail) if ((int)$detail['id']===$detailId) return (float)$detail['amount'];
        return 0.0;
    }

    private function robotDetailWin(array $record, int $detailId): float
    {
        foreach ($record['details'] as $detail) if ((int)$detail['id']===$detailId) return $detail['eval_win']===null?0.0:(float)$detail['eval_win'];
        return 0.0;
    }

    /** Dry-run: compute a signed node-profit plan without writing anything. */
    public function robotPlan(Request $request): \think\response\Json
    {
        $siteId=$this->scopedSiteId($request);
        $data=$request->post();
        [$lottery,$issue,$draw,$userIds,$targetProfit,$nodeId]=$this->robotParams($data,$siteId);
        $plan=$this->buildNumberOnlyRobotPlan($lottery,$issue,$draw,$userIds,$targetProfit,$nodeId,$siteId);
        return $this->reply($plan);
    }

    /**
     * Apply the robot plan: regenerate it server-side, reject stale previews,
     * then rewrite number fields only inside one transaction. Settled records
     * are reopened/resettled and the affected daily report is materialized.
     */
    public function robotApply(Request $request): \think\response\Json
    {
        $siteId=$this->scopedSiteId($request); $session=$this->session($request);
        $data=$request->post();
        [$lottery,$issue,$draw,$userIds,$targetProfit,$nodeId]=$this->robotParams($data,$siteId);
        $plan=$this->buildNumberOnlyRobotPlan($lottery,$issue,$draw,$userIds,$targetProfit,$nodeId,$siteId);
        $planToken=trim((string)($data['plan_token']??''));
        if($planToken===''||!hash_equals((string)$plan['plan_token'],$planToken)) throw new \RuntimeException('方案数据已变化，请重新生成预览');
        if(!$plan['within_tolerance']) throw new \RuntimeException('当前方案未达到目标上下 30% 区间，请调整目标或组织范围后重试');
        $settledIds=[];
        $amountBefore=$this->robotAmountSnapshot($plan['items']);
        $changed=Db::transaction(function()use($plan,$issue,$siteId,&$settledIds,$amountBefore):int{
            $changed=0;
            foreach ($plan['items'] as $item) {
                $this->applyRobotNumberOnlyItem($item,$issue,$siteId,$settledIds);
                $changed++;
            }
            if(!hash_equals($amountBefore,$this->robotAmountSnapshot($plan['items']))) throw new \RuntimeException('金额不可变校验失败，已撤销本次改单');
            return $changed;
        });
        foreach ($settledIds as $settledId) $this->resettleRebuiltRecord((int)$settledId,(string)$lottery['name'],$issue);
        if(!hash_equals($amountBefore,$this->robotAmountSnapshot($plan['items']))) throw new \RuntimeException('重结算后金额不可变校验失败');
        (new \app\service\ReportMaterializer())->refreshDay((int)$plan['node']['site_id'] ?: (int)($siteId??0),(string)$plan['day']);
        AuditLogger::write($session,'robot_adjust','bet_records',[
            'lottery_id'=>(int)$lottery['id'],'issue_no'=>$issue,'draw'=>$draw,
            'scope_user_ids'=>$plan['scope_user_ids'],'scope_user_count'=>count($plan['scope_user_ids']),
            'selected_robot_ids'=>$plan['selected_robot_ids'],'selected_robot_count'=>count($plan['selected_robot_ids']),'node_id'=>$nodeId,'target_profit'=>$targetProfit,
            'achieved_profit'=>$plan['daily_profit_after'],'amount_unchanged'=>true,'changed'=>$changed,
        ],(string)$request->ip());
        return $this->reply(['changed'=>$changed,'resettled'=>count($settledIds),'achieved_profit'=>$plan['daily_profit_after'],'amount_unchanged'=>true],'所选机器人只改号码完成');
    }

    /** @return array{0:array,1:string,2:string,3:array,4:float,5:int} */
    private function robotParams(array $data, ?int $siteId): array
    {
        $lotteryId=(int)($data['lottery_id']??0); $issue=trim((string)($data['issue_no']??''));
        $lottery=null;
        foreach ($this->lotteries($siteId) as $item) if ((int)$item['id']===$lotteryId) { $lottery=$item; break; }
        if (!$lottery) throw new \InvalidArgumentException('请选择有效彩种');
        if ($issue==='') throw new \InvalidArgumentException('请选择期号');
        $draw=preg_replace('/\D/','',(string)($data['draw']??''));
        if (strlen($draw)!==3) throw new \InvalidArgumentException('自动改码需要 3 位预开奖号码');
        $userIds=$data['user_ids']??null;
        if (is_string($userIds)) $userIds=explode(',',$userIds);
        if (!is_array($userIds)) throw new \InvalidArgumentException('请选择要自动改码的机器人');
        $userIds=array_values(array_unique(array_filter(array_map('intval',$userIds),static fn(int $id):bool=>$id>0)));
        if ($userIds===[]) throw new \InvalidArgumentException('请选择要自动改码的机器人');
        $targetProfit=(float)($data['target_profit']??NAN);
        if (!is_finite($targetProfit)) throw new \InvalidArgumentException('请输入有效的正负目标盈亏');
        return [$lottery,$issue,$draw,$userIds,$targetProfit,(int)($data['node_id']??0)];
    }

    /** Execute a number-only item and assert every stored stake is unchanged. */
    private function applyRobotNumberOnlyItem(array $item,string $issue,?int $siteId,array &$settledIds): void
    {
        $recordId=(int)($item['record_id']??0);
        $query=Db::name('bet_records')->where('id',$recordId)->where('issue_no',$issue)->whereIn('status',['pending','won','unwon'])->lock(true);
        if($siteId!==null)$query->where('site_id',$siteId);
        $rows=$query->select()->toArray();$record=$rows[0]??null;
        if(!$record)throw new \RuntimeException('主单 #'.$recordId.' 状态已变化，请重新生成方案');
        if(abs((float)$record['amount']-(float)$item['old_amount'])>=0.005||(string)$record['source_text']!==(string)$item['old_source'])
            throw new \RuntimeException('主单 #'.$recordId.' 内容或金额已变化，请重新生成方案');
        $wasSettled=in_array((string)$record['status'],['won','unwon'],true);
        if($wasSettled)$this->reopenSettledRecord($record);
        $detailRows=Db::name('bet_details')->where('bet_record_id',$recordId)->order('id asc')->lock(true)->select()->toArray();
        $details=[];foreach($detailRows as $detail)$details[(int)$detail['id']]=$detail;
        $source=(string)$record['source_text'];$formatted=(string)($record['formatted_text']??'');$sourceTouched=false;$replaced=[];
        foreach($item['details'] as $change){
            $detailId=(int)$change['detail_id'];$detail=$details[$detailId]??null;
            if(!$detail)throw new \RuntimeException('注单明细已变化，请重新生成方案');
            if(abs((float)$detail['amount']-(float)$change['old_amount'])>=0.005||(string)$detail['number_text']!==(string)$change['old_number'])
                throw new \RuntimeException('注单明细号码或金额已变化，请重新生成方案');
            $newNumber=(string)$change['new_number'];$newDetailSource=(string)($change['new_detail_source']??'');
            $numberChanged=$newNumber!==''&&$newNumber!==(string)$detail['number_text'];
            $detailSourceChanged=$newDetailSource!==''&&$newDetailSource!==(string)$detail['source_text'];
            if(!$numberChanged&&!$detailSourceChanged&&($change['pairs']??[])===[])continue;
            foreach($change['pairs']??[] as $pair){
                $old=(string)($pair['old']??'');$new=(string)($pair['new']??'');if($old===''||$old===$new)continue;
                $patched=$this->replaceRawToken($source,$old,$new);
                if($patched===$source&&!isset($replaced[$old]))throw new \RuntimeException('原始注单文本已变化，请重新生成方案');
                if($patched!==$source){$source=$patched;$replaced[$old]=true;$sourceTouched=true;}
                $formattedPatched=$this->replaceRawToken($formatted,$old,$new);if($formattedPatched!==$formatted)$formatted=$formattedPatched;
                Db::name('agent_interceptions')->where('bet_detail_id',$detailId)->where('number_key',$old)->update(['number_key'=>$new]);
            }
            $update=['win_amount'=>'0.00','status'=>'pending','matched_count'=>0];
            if($numberChanged)$update['number_text']=$newNumber;if($detailSourceChanged)$update['source_text']=$newDetailSource;
            Db::name('bet_details')->where('id',$detailId)->update($update);
            $stop=[];if($numberChanged)$stop['number_text']=$newNumber;if($detailSourceChanged)$stop['source_text']=$newDetailSource;
            if($stop!==[])Db::name('user_stop_drops')->where('bet_detail_id',$detailId)->update($stop);
        }
        $total=(float)Db::name('bet_details')->where('bet_record_id',$recordId)->sum('amount');
        if(abs($total-(float)$record['amount'])>=0.005)throw new \RuntimeException('主单金额校验失败，已撤销本次改单');
        $update=['win_amount'=>'0.00','status'=>'pending'];
        if($sourceTouched){$update['source_text']=$source;if($formatted!=='')$update['formatted_text']=$formatted;}
        Db::name('bet_records')->where('id',$recordId)->update($update);
        $submissionId=(int)($record['submission_id']??0);
        if($sourceTouched&&$submissionId>0&&Db::query("SHOW TABLES LIKE 'bet_submissions'")!==[]){
            $submissionUpdate=['source_text'=>$source];if($formatted!=='')$submissionUpdate['formatted_text']=$formatted;
            Db::name('bet_submissions')->where('id',$submissionId)->update($submissionUpdate);
        }
        if($wasSettled)$settledIds[]=$recordId;
    }

    /** Hash every amount field reachable from the planned records. */
    private function robotAmountSnapshot(array $items): string
    {
        $recordIds=array_values(array_unique(array_map(static fn(array $item):int=>(int)$item['record_id'],$items)));
        if($recordIds===[])return hash('sha256','[]');
        $records=Db::name('bet_records')->whereIn('id',$recordIds)->field('id,submission_id,amount')->order('id asc')->select()->toArray();
        $details=Db::name('bet_details')->whereIn('bet_record_id',$recordIds)->field('id,bet_record_id,amount')->order('id asc')->select()->toArray();
        $detailIds=array_map('intval',array_column($details,'id'));
        $stops=$detailIds===[]?[]:Db::name('user_stop_drops')->whereIn('bet_detail_id',$detailIds)->field('id,bet_detail_id,original_amount,actual_amount,stop_amount')->order('id asc')->select()->toArray();
        $submissionIds=array_values(array_unique(array_filter(array_map('intval',array_column($records,'submission_id')))));
        $submissions=$submissionIds===[]||Db::query("SHOW TABLES LIKE 'bet_submissions'")===[]?[]:Db::name('bet_submissions')->whereIn('id',$submissionIds)->field('id,amount')->order('id asc')->select()->toArray();
        return hash('sha256',json_encode([$records,$details,$stops,$submissions],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    /** Execute one plan item: patch detail numbers and/or scale winning amounts. */
    private function applyRobotItem(array $item, string $issue, ?int $siteId, array &$settledIds): void
    {
        $recordId=(int)($item['record_id']??0); if ($recordId<1) return;
        $query=Db::name('bet_records')->where('id',$recordId)->where('issue_no',$issue)->whereIn('status',['pending','won','unwon'])->lock(true);
        if ($siteId!==null) $query->where('site_id',$siteId);
        $recordRows=$query->select()->toArray();
        $record=$recordRows[0]??null;
        if (!$record) throw new \RuntimeException('主单 #'.$recordId.' 状态已变化，请重新生成方案');
        $wasSettled=in_array((string)$record['status'],['won','unwon'],true);
        if ($wasSettled) $this->reopenSettledRecord($record);
        $detailRows=Db::name('bet_details')->where('bet_record_id',$recordId)->order('id asc')->select()->toArray();
        $detailsById=[]; foreach ($detailRows as $detail) $detailsById[(int)$detail['id']]=$detail;
        $source=(string)($record['source_text']??''); $formatted=(string)($record['formatted_text']??'');
        $sourceTouched=false; $replaced=[];
        foreach (($item['details']??[]) as $dItem) {
            $detailId=(int)($dItem['detail_id']??0);
            $detail=$detailsById[$detailId]??null;
            if (!$detail) throw new \RuntimeException('注单明细已变化，请重新生成方案');
            // 1) number/source flips: patch detail text, raw source and interception keys
            $newNumber=(string)($dItem['new_number']??'');
            $newDetailSource=(string)($dItem['new_detail_source']??'');
            $numberChanged=$newNumber!=='' && $newNumber!==(string)$detail['number_text'];
            $sourceChanged=$newDetailSource!=='' && $newDetailSource!==(string)$detail['source_text'];
            if ($numberChanged || $sourceChanged || ($dItem['pairs']??[])!==[]) {
                foreach (($dItem['pairs']??[]) as $pair) {
                    $old=(string)($pair['old']??'');$new=(string)($pair['new']??'');
                    if ($old===''||$old===$new) continue;
                    $patched=$this->replaceRawToken($source,$old,$new);
                    if ($patched===$source) {
                        if (!isset($replaced[$old])) throw new \RuntimeException('原始注单文本已变化，请重新生成方案');
                    } else { $source=$patched; $replaced[$old]=true; $sourceTouched=true; }
                    $formattedPatched=$this->replaceRawToken($formatted,$old,$new);
                    if ($formattedPatched!==$formatted) $formatted=$formattedPatched;
                    Db::name('agent_interceptions')->where('bet_detail_id',$detailId)->where('number_key',$old)->update(['number_key'=>$new]);
                }
                $detailUpdate=['win_amount'=>'0.00','status'=>'pending','matched_count'=>0];
                if ($numberChanged) $detailUpdate['number_text']=$newNumber;
                if ($sourceChanged) $detailUpdate['source_text']=$newDetailSource;
                Db::name('bet_details')->where('id',$detailId)->update($detailUpdate);
                $stopUpdate=[];
                if ($numberChanged) $stopUpdate['number_text']=$newNumber;
                if ($sourceChanged) $stopUpdate['source_text']=$newDetailSource;
                if ($stopUpdate!==[]) Db::name('user_stop_drops')->where('bet_detail_id',$detailId)->update($stopUpdate);
                $detailsById[$detailId]['number_text']=$numberChanged?$newNumber:$detail['number_text'];
                $detailsById[$detailId]['source_text']=$sourceChanged?$newDetailSource:$detail['source_text'];
            }
            // 2) amount scaling on winning details
            $newAmount=(string)($dItem['new_amount']??'');
            if ($newAmount!=='' && abs((float)$newAmount-(float)$detail['amount'])>=0.005) {
                Db::name('bet_details')->where('id',$detailId)->update(['amount'=>$newAmount,'win_amount'=>'0.00','status'=>'pending','matched_count'=>0]);
                Db::name('user_stop_drops')->where('bet_detail_id',$detailId)->update(['original_amount'=>$newAmount,'actual_amount'=>$newAmount]);
                $detailsById[$detailId]['amount']=$newAmount;
                // 金额字样同步：各N元→每注新分摊，裸N元→明细新总额。
                // 明细/停押/原始注单/格式化文本一并改，用户端不再显示旧金额。
                $curDetailSource=(string)($detailsById[$detailId]['source_text']??'');
                $pickNumber=$numberChanged?$newNumber:(string)$detail['number_text'];
                $patchedSource=$this->robotAmountSourceText($curDetailSource,(float)$newAmount,$this->robotPickCount($pickNumber));
                if ($patchedSource!==$curDetailSource) {
                    Db::name('bet_details')->where('id',$detailId)->update(['source_text'=>$patchedSource]);
                    Db::name('user_stop_drops')->where('bet_detail_id',$detailId)->update(['source_text'=>$patchedSource]);
                    $source=$this->replaceFirstText($source,$curDetailSource,$patchedSource);
                    $formatted=$this->replaceFirstText($formatted,$curDetailSource,$patchedSource);
                    $detailsById[$detailId]['source_text']=$patchedSource;
                    $sourceTouched=true;
                }
            }
        }
        $newTotal=(float)Db::name('bet_details')->where('bet_record_id',$recordId)->sum('amount');
        $recordUpdate=['amount'=>number_format($newTotal,2,'.',''),'win_amount'=>'0.00','status'=>'pending'];
        if ($sourceTouched) {
            $recordUpdate['source_text']=$source;
            if ($formatted!=='') $recordUpdate['formatted_text']=$formatted;
        }
        Db::name('bet_records')->where('id',$recordId)->update($recordUpdate);
        $this->syncRobotSubmission($record,$sourceTouched?$source:null,$sourceTouched?$formatted:null);
        // 3) usage delta: bets charged today's usage once, so a same-day
        // amount change must adjust the daily counter too.
        $delta=$newTotal-(float)$record['amount'];
        if (abs($delta)>=0.005 && substr((string)($record['placed_at']??''),0,10)===\app\service\DailyScoreUsage::today()) {
            $userRows=Db::name('site_users')->where('id',(int)$record['user_id'])->where('site_id',(int)$record['site_id'])->lock(true)->select()->toArray();
            $user=$userRows[0]??null;
            if ($user) {
                $before=(float)$user['balance']+(float)$user['credit_balance']-(float)$user['used_balance'];
                \app\service\DailyScoreUsage::change((int)$record['user_id'],$delta);
                CreditLedger::write(['tenant_id'=>(int)$record['tenant_id'],'site_id'=>(int)$record['site_id']],
                    (int)($user['organization_id']??0)?:null,'user',(int)$record['user_id'],(int)$record['user_id'],$recordId,null,$issue,
                    -$delta,$before,$before-$delta,'机器人改单调整下注金额','bet');
            }
        }
        if ($wasSettled) $settledIds[]=$recordId;
    }

    /** Recompute the parent submission totals after a robot edit. */
    private function syncRobotSubmission(array $record, ?string $source, ?string $formatted): void
    {
        $submissionId=(int)($record['submission_id']??0);
        if ($submissionId<1 || Db::query("SHOW TABLES LIKE 'bet_submissions'")===[]) return;
        $rows=Db::name('bet_records')->where('submission_id',$submissionId)->select()->toArray();
        if ($rows===[]) return;
        $amount=0.0;$count=0;$win=0.0;$sealed=0;$status='pending';
        foreach ($rows as $row) {
            $amount+=(float)($row['amount']??0);$count+=(int)($row['bet_count']??0);
            $win+=(float)($row['win_amount']??0);$sealed=max($sealed,(int)($row['sealed']??0));
            $rowStatus=(string)($row['status']??'pending');
            if ($rowStatus==='refunded') $status='refunded';
            elseif ($status==='pending' && $rowStatus==='won') $status='won';
            elseif ($status==='pending' && $rowStatus==='unwon') $status='unwon';
            if ($rowStatus==='pending') $status='pending';
        }
        if ($status!=='refunded' && $win>0) $status='won';
        $update=['amount'=>number_format($amount,2,'.',''),'bet_count'=>$count,
            'win_amount'=>number_format($win,2,'.',''),'status'=>$status,'sealed'=>$sealed];
        if ($source!==null) $update['source_text']=$source;
        if ($formatted!==null && $formatted!=='') $update['formatted_text']=$formatted;
        Db::name('bet_submissions')->where('id',$submissionId)->update($update);
    }
}
