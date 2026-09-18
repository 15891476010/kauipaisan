<?php
declare(strict_types=1);

namespace app\controller;

use app\service\OrganizationHierarchy;
use app\service\AgentReportScope;
use app\service\SequentialProfitShare;
use think\Request;
use think\facade\Cache;
use think\facade\Db;

final class AgentReport
{
    private function reply(mixed $data=null,string $message='ok',int $code=0): \think\response\Json
    {
        return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]);
    }

    private function session(Request $request): array
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token!==''?Cache::get('token:'.$token):null;
        if(!is_array($session)||($session['scope']??'')!=='agent') throw new \RuntimeException('未登录或登录已过期');
        if((int)($session['site_id']??0)<1) throw new \RuntimeException('当前代理未绑定站点');
        return $session;
    }

    public function index(Request $request): \think\response\Json
    {
        $session=$this->session($request);
        try {
            [$from,$to]=$this->dates($request,$session);
            $scope=new AgentReportScope($session,(int)$request->param('organization_id',0));
        } catch (\InvalidArgumentException $e) { return $this->reply(null,$e->getMessage(),422); }
        $lotteries=$this->lotteries($request);
        $viewSession=$scope->session($session);
        $rows=$this->rows($viewSession,$from,$to,$lotteries);
        $groups=$this->memberList($scope,$viewSession,$rows);
        return $this->reply(array_merge($scope->context(),$groups,['summary'=>$this->aggregate($rows),'report_levels'=>$this->reportLevels($viewSession),'from'=>$from,'to'=>$to,'lotteries'=>$lotteries]));
    }

    public function monthly(Request $request): \think\response\Json
    {
        $session=$this->session($request);
        try {
            [$from,$to]=$this->dates($request,$session);
            $scope=new AgentReportScope($session,(int)$request->param('organization_id',0));
        } catch (\InvalidArgumentException $e) { return $this->reply(null,$e->getMessage(),422); }
        $lotteries=$this->lotteries($request);
        $viewSession=$scope->session($session);
        $rows=$this->rows($viewSession,$from,$to,$lotteries); $groups=[];
        foreach($rows as $row) {
            $issue=(string)$row['issue_no'];
            if(!isset($groups[$issue])) $groups[$issue]=[];
            $groups[$issue][]=$row;
        }
        $list=[];
        foreach($groups as $issue=>$group) $list[]=['issue_no'=>$issue,'draw_date'=>$this->issueDate($group),'summary'=>$this->aggregate($group)];
        usort($list,static fn(array $a,array $b): int=>strcmp((string)$b['issue_no'],(string)$a['issue_no']));
        return $this->reply(array_merge($scope->context(),['list'=>$list,'total'=>$this->aggregate($rows),'issue_count'=>AgentReportScope::issueCount($rows),'report_levels'=>$this->reportLevels($viewSession),'from'=>$from,'to'=>$to,'lotteries'=>$lotteries]));
    }

    public function issues(Request $request): \think\response\Json
    {
        $session=$this->session($request); $siteId=(int)$session['site_id']; $tenantId=(int)($session['tenant_id']??1); $lottery=trim((string)$request->param('lottery',''));
        $requestedFrom=trim((string)$request->param('from','')); $requestedTo=trim((string)$request->param('to',''));
        if($requestedFrom!==''||$requestedTo!=='') {
            try { [$from,$to]=$this->dates($request,$session); } catch (\InvalidArgumentException $e) { return $this->reply(null,$e->getMessage(),422); }
        } else {
            $from=date('Y-m-01'); $to=date('Y-m-t');
        }
        $query=Db::name('lottery_histories')->alias('h')->join('lotteries l','l.id=h.lottery_id')->join('site_lotteries sl','sl.lottery_id=l.id')
            ->where('sl.site_id',$siteId)->where('sl.tenant_id',$tenantId)->where('l.tenant_id',$tenantId)
            ->where('l.status',1)->whereNull('l.deleted_at')->where('h.draw_day','>=',$from)->where('h.draw_day','<=',$to);
        if($lottery!=='') $query->where('l.name',$lottery);
        // A single monthly dropdown needs every issue in the selected month;
        // the general draw-history display limit must not truncate it.
        $items=$query->field('h.code AS issue_no,h.draw_day AS date')->order('h.draw_day desc')->order('h.code desc')->limit(100)->select()->toArray();
        $seen=[]; $list=[]; foreach($items as $row) { $issue=(string)$row['issue_no']; if(isset($seen[$issue])) continue; $seen[$issue]=true; $list[]=['issue_no'=>$issue,'date'=>(string)$row['date']]; }
        return $this->reply(['list'=>$list,'from'=>$from,'to'=>$to]);
    }

    private function rows(array $session,string $from,string $to,array $lotteries): array
    {
        $siteId=(int)$session['site_id'];
        $waterRate=$this->waterRate($siteId);
        $siteSettings=Db::name('sites')->where('id',$siteId)->value('settings');
        $siteSettings=is_string($siteSettings)?json_decode($siteSettings,true):(is_array($siteSettings)?$siteSettings:[]);
        $siteCap=max(0,min(100,(float)($siteSettings['max_profit_share_rate']??100)));
        $chainCache=[];
        // Persisted groups carry the whole history; only the current day is
        // computed live so unsettled projections and just-placed bets stay
        // exact. This keeps month-range queries at a few thousand rows even
        // when the betting volume grows by orders of magnitude.
        $today=date('Y-m-d');
        $rows=[];
        $materializedTo=min($to,date('Y-m-d',strtotime($today)-86400));
        if($from<=$materializedTo)$rows=array_merge($rows,$this->materializedRows($session,$from,$materializedTo,$lotteries));
        if($to>=$today)$rows=array_merge($rows,$this->liveRows($session,max($from,$today),$to,$lotteries));
        // Imported batches keep the reference report snapshot until it is
        // materialized into local bet tables. Include those rows so a newly
        // created总代理 immediately sees the selected date range and members.
        $rows=array_merge($rows,$this->importedRows($session,$from,$to,$lotteries));
        if($rows===[]) return [];
        $nodeLevels=[];
        foreach(Db::name('organization_nodes')->where('site_id',$siteId)->field('id,level')->select()->toArray() as $nodeRow) $nodeLevels[(int)$nodeRow['id']]=(string)$nodeRow['level'];
        $currentOrganizationId=(int)($session['organization_id']??0);
        foreach($rows as &$row) {
            $amount=(float)$row['amount']; $win=(float)$row['win_amount']; $rebate=(float)$row['rebate'];
            $intercepted=(float)($row['intercepted']??0);
            // Occupation is based on the member's own P/L and configured
            // occupation percentage. It is independent of how much capacity
            // was actually intercepted. The single configured water amount
            // is based on the occupation amount.
            $memberProfit=$win+$rebate-$amount;
            // Use the same remaining-profit allocation as settlement: the
            // nearest organization receives its percentage first, and only
            // the remainder is passed to its parent.
            $chain=$this->organizationChain($siteId,(int)($row['organization_id']??0),$chainCache);
            if ($chain===[]) {
                if ((float)($row['share_rate']??0)>0) {
                    // Legacy members without an organization retain their direct
                    // historical percentage in the report.
                    // Use a non-root sentinel parent so the legacy percentage is
                    // applied instead of being promoted to the mandatory 100%
                    // root allocation by SequentialProfitShare.
                    $chain=[['id'=>0,'parent_id'=>1,'level'=>'agent','share_rate'=>(float)$row['share_rate']]];
                } else {
                    // Members without an organization book everything to the
                    // root director, matching settlement's root fallback.
                    $root=OrganizationHierarchy::rootForSite($siteId);
                    if($root) $chain=[$root];
                }
            }
            // Per-level figures: a level column is populated only when a node
            // at that level sits in this member's organization chain. Levels
            // absent from the chain (for example an agent opened directly
            // under the director, skipping 总代理/股东) stay at zero.
            $levelBases=[];
            $chainLevels=[];
            foreach($chain as $chainNode) {
                $levelKey=(string)($chainNode['level']??'');
                if($levelKey==='') continue;
                $chainLevels[$levelKey]=true;
                if(!isset($levelBases[$levelKey])) $levelBases[$levelKey]=['amount'=>0.0,'share_base'=>0.0];
                $levelBases[$levelKey]['amount']+=$amount;
            }
            $recordLedger=null;
            if((int)($row['settled']??0)===1&&isset($row['_ledger'])&&is_array($row['_ledger'])) $recordLedger=$row['_ledger'];
            $allocationAmount=0.0;$currentShareRate=0.0;
            if($recordLedger!==null){
                // Snapshot: booked amounts count once per issue group no
                // matter how many details the group spans; levels that
                // existed at settle time but left the live chain still
                // surface their stake.
                foreach($recordLedger as $entry){
                    $levelKey=$entry['level']!==''?$entry['level']:(string)($nodeLevels[$entry['organization_id']]??'');
                    $memberView=-$entry['booked'];
                    if($levelKey!==''){
                        if(!isset($levelBases[$levelKey])) $levelBases[$levelKey]=['amount'=>0.0,'share_base'=>0.0];
                        $levelBases[$levelKey]['share_base']+=$memberView;
                        if(!isset($chainLevels[$levelKey])) $levelBases[$levelKey]['amount']+=$amount;
                    }
                    if($entry['organization_id']===$currentOrganizationId){$allocationAmount+=$memberView;$currentShareRate=$entry['share_rate'];}
                }
            } else {
                $allocations=SequentialProfitShare::allocate($memberProfit,$chain,$siteCap);
                $currentAllocation=null;
                foreach($allocations as $allocation) {
                    $levelKey=(string)($allocation['node']['level']??'');
                    if($levelKey!=='') $levelBases[$levelKey]['share_base']=($levelBases[$levelKey]['share_base']??0)+(float)$allocation['amount'];
                    if((int)($allocation['node']['id']??0)===$currentOrganizationId)$currentAllocation=$allocation;
                }
                // Viewers absent from the member's chain project the root
                // remainder, matching the previous fallback behavior.
                $currentAllocation ??= (($currentOrganizationId>0 && $allocations!==[]) ? end($allocations) : null);
                $allocationAmount=(float)($currentAllocation['amount']??0);
                $currentShareRate=(float)($currentAllocation['share_rate']??0);
            }
            // Occupation amount is always displayed as a positive principal.
            // The sign belongs only to occupation P/L: a positive member P/L
            // means the member won and the organization must pay it out.
            $occupationAmount=abs($allocationAmount);
            $hasShare=$currentShareRate>0;
            $water=$occupationAmount*$waterRate;
            // The single site-wide 明水 is part of occupation P/L. There is no
            // separate offline/dark-water stream.
            $direction=$memberProfit>0?-1.0:1.0;
            // Confirmed formula: water money = occupation amount × 0.085;
            // occupation P/L is the signed occupation amount plus that water.
            $shareProfit=$direction*($occupationAmount+$water);
            $agentProfit=$shareProfit;
            $betCount=(int)($row['import_bet_count']??$row['number_count']??0);
            $houseProfit=-$memberProfit;
            $row['metrics']=['bet_count'=>max(1,$betCount),'amount'=>$amount,'win_amount'=>$win,'water'=>$rebate,'member_profit'=>$memberProfit,'share_amount'=>$occupationAmount,'share_profit'=>$shareProfit,'offline_water'=>0.0,'agent_water'=>$water,'agent_profit'=>$agentProfit,'platform_amount'=>max(0,$amount-$intercepted),'platform_profit'=>$houseProfit-$shareProfit,
                // Hidden aggregation inputs: occupation is calculated on the
                // member's net P/L after grouping, never by summing absolute
                // P/L for individual bet lines.
                'share_base'=>$allocationAmount,'share_rate'=>$currentShareRate,'water_rate'=>$waterRate,'has_share'=>$hasShare?1:0,'levels'=>$levelBases];
        }
        unset($row); return $rows;
    }

    /**
     * Live grouping for the current day: identical to the materializer's
     * output shape so both sources feed the same post-processing loop.
     * Unsettled bets must project with the live chain, which is exactly
     * what the persisted rows cannot know in advance.
     */
    private function liveRows(array $session,string $from,string $to,array $lotteries): array
    {
        $siteId=(int)$session['site_id'];
        $query=Db::name('bet_details')->alias('d')
            ->join('bet_records r','r.id=d.bet_record_id')
            ->join('site_users u','u.id=d.user_id')
            ->leftJoin('user_stop_drops s','s.bet_detail_id=d.id')
            ->where('d.site_id',$siteId)->where('u.site_id',$siteId)->whereNull('u.deleted_at')->where('d.placed_at','>=',$from.' 00:00:00')->where('d.placed_at','<=',$to.' 23:59:59')
            ->where('r.status','<>','refunded');
        OrganizationHierarchy::applyUserScope($query,$session,'d.user_id');
        if($lotteries!==[]) {
            $marks=implode(',',array_fill(0,count($lotteries),'?'));
            // Ordinary bets do not have a stop-drop row; resolve their lottery
            // through the issue history via EXISTS so 福彩3D/排列三 sharing the
            // same issue code cannot duplicate a grouped row.
            $query->whereRaw('(s.lottery IN ('.$marks.') OR d.lottery_name IN ('.$marks.') OR EXISTS(SELECT 1 FROM lottery_histories lh JOIN lotteries l ON l.id=lh.lottery_id WHERE lh.code=d.issue_no AND l.name IN ('.$marks.')))',array_merge($lotteries,$lotteries,$lotteries));
        }
        $rows=$query->field(
            'd.user_id,u.username,u.organization_id,u.interception_rate AS share_rate,d.issue_no,'.
            "CASE WHEN r.status IN ('won','unwon') THEN 1 ELSE 0 END AS settled,".
            'COUNT(d.id) AS detail_count,'.
            "SUM(LENGTH(d.number_text)-LENGTH(REPLACE(d.number_text,' ',''))+LENGTH(d.number_text)-LENGTH(REPLACE(REPLACE(d.number_text,',',''),'，',''))+1) AS number_count,".
            'SUM(d.amount) AS amount,SUM(d.win_amount) AS win_amount,SUM(d.rebate) AS rebate,'.
            'MAX(d.placed_at) AS placed_at'
        )->group('d.user_id,d.issue_no,settled')->select()->toArray();
        if($rows===[]) return [];
        // Intercepted amounts aggregate per (member, issue) the same way the
        // details do; the per-detail whereIn list no longer exists.
        $interceptMap=[];
        foreach(Db::name('agent_interceptions')->alias('i')
            ->join('bet_details d','d.id=i.bet_detail_id')
            ->join('bet_records r','r.id=d.bet_record_id')
            ->whereNull('i.released_at')->where('r.site_id',$siteId)->where('r.status','<>','refunded')
            ->where('d.placed_at','>=',$from.' 00:00:00')->where('d.placed_at','<=',$to.' 23:59:59')
            ->field('r.user_id,r.issue_no,SUM(i.intercepted_amount) AS intercepted')
            ->group('r.user_id,r.issue_no')->select()->toArray() as $irow){
            $interceptMap[(int)$irow['user_id'].'|'.(string)$irow['issue_no']]=(float)$irow['intercepted'];
        }
        // Settled records keep their per-node allocation snapshot in the
        // credit ledger (share_rate + amount at settle time). A share-rate
        // change today must not rewrite what settled bets already booked;
        // only unsettled rows project with the live chain.  Booked amounts
        // aggregate per (member, issue, node) directly in SQL.
        $settledLedger=[];
        $ledgerQuery=Db::name('organization_credit_ledger')->alias('l')
            ->join('bet_records r','r.id=l.related_bet_record_id')
            ->where('l.site_id',$siteId)->where('l.source_type','settlement_share')->where('r.status','<>','refunded')
            ->where('r.placed_at','>=',$from.' 00:00:00')->where('r.placed_at','<=',$to.' 23:59:59');
        OrganizationHierarchy::applyUserScope($ledgerQuery,$session,'r.user_id');
        foreach($ledgerQuery->field(
            'r.user_id,r.issue_no,l.organization_id,l.direction,SUM(l.amount) AS total,'.
            "JSON_UNQUOTE(JSON_EXTRACT(l.metadata,'$.organization_level')) AS lvl,".
            "JSON_UNQUOTE(JSON_EXTRACT(l.metadata,'$.share_rate')) AS rate"
        )->group('r.user_id,r.issue_no,l.organization_id,l.direction')->select()->toArray() as $ledgerRow){
            $key=(int)$ledgerRow['user_id'].'|'.(string)$ledgerRow['issue_no'];
            $settledLedger[$key][]=[
                'organization_id'=>(int)$ledgerRow['organization_id'],
                'level'=>(string)($ledgerRow['lvl']??''),
                'share_rate'=>(float)($ledgerRow['rate']??0),
                'booked'=>((string)$ledgerRow['direction']==='in'?1.0:-1.0)*(float)$ledgerRow['total'],
            ];
        }
        foreach($rows as &$row){
            $key=(int)$row['user_id'].'|'.(string)$row['issue_no'];
            $row['intercepted']=$interceptMap[$key]??0;
            $row['_ledger']=$settledLedger[$key]??null;
        }
        unset($row);
        return $rows;
    }

    /**
     * Persisted (member, issue, lottery, settled) groups maintained by
     * ReportMaterializer. Member attributes join site_users live so a
     * username or organization change never requires a rebuild.
     */
    private function materializedRows(array $session,string $from,string $to,array $lotteries): array
    {
        $siteId=(int)$session['site_id'];
        $query=Db::name('report_member_issue')->alias('m')
            ->join('site_users u','u.id=m.user_id AND u.site_id=m.site_id')
            ->where('m.site_id',$siteId)->whereNull('u.deleted_at')
            ->where('m.day','>=',$from)->where('m.day','<=',$to);
        OrganizationHierarchy::applyUserScope($query,$session,'m.user_id');
        if($lotteries!==[])$query->whereIn('m.lottery_name',$lotteries);
        $rows=$query->field(
            'm.user_id,u.username,u.organization_id,u.interception_rate AS share_rate,m.issue_no,m.settled,'.
            'm.detail_count,m.number_count,m.amount,m.win_amount,m.rebate,m.intercepted,m.placed_at,m.ledger_json'
        )->select()->toArray();
        foreach($rows as &$row){
            $json=(string)($row['ledger_json']??'');
            $row['_ledger']=$json!==''?json_decode($json,true):null;
            unset($row['ledger_json']);
        }
        unset($row);
        return $rows;
    }

    private function importedOrganizationMap(int $batchId,int $siteId): array
    {
        $map=[];$externalNodes=[];
        foreach(Db::name('organization_nodes')->where('site_id',$siteId)->whereNull('deleted_at')->field('id,settings')->select()->toArray() as $node){$settings=json_decode((string)($node['settings']??''),true);$external=trim((string)($settings['external_id']??''));if($external!=='')$externalNodes[$external]=(int)$node['id'];}
        $records=Db::name('agent_import_records')->where('batch_id',$batchId)->where('entity_type','account')->whereIn('action',['created_node','created_member','reused'])->order('id asc')->select()->toArray();
        foreach($records as $record){
            $external=trim((string)($record['external_id']??'')); if($external==='')continue;
            $payload=json_decode((string)($record['payload']??''),true); $source=is_array($payload)?(array)($payload['source']??[]):[];
            $local=(int)($record['local_id']??0); $action=(string)($record['action']??'');
            $sourceType=(int)($source['tp']??0);
            if($action==='created_member'||$sourceType>=6){$parentExternal=trim((string)($source['pi']??''));$org=(int)($externalNodes[$parentExternal]??0);if($org<1)$org=(int)($payload['organization_id']??0);if($org<1&&$local>0)$org=(int)Db::name('site_users')->where('id',$local)->where('site_id',$siteId)->value('organization_id');if($org>0)$map[$external]=$org;}
            else { $org=$local; if($action==='reused')$org=(int)($source['organization_id']??Db::name('organization_accounts')->where('id',$local)->where('site_id',$siteId)->value('organization_id')); if($org>0)$map[$external]=$org; }
        }
        return $map;
    }

    private function importedRows(array $session,string $from,string $to,array $lotteries): array
    {
        $siteId=(int)$session['site_id']; $target=(int)($session['organization_id']??0); $out=[]; $seen=[];
        if($lotteries!==[]&&!in_array('福彩3D',$lotteries,true))return [];
        $allowed=$target>0?array_values(array_unique(array_merge([$target],array_map('intval',OrganizationHierarchy::descendantIds($target))))):[];
        $batches=Db::name('agent_import_batches')->where('tenant_id',(int)($session['tenant_id']??1))->where('site_id',$siteId)->where('status','completed')->where('from_date','<=',$to)->where('to_date','>=',$from);
        foreach($batches->order('id desc')->limit(20)->select()->toArray() as $batch){
            $organizationMap=$this->importedOrganizationMap((int)$batch['id'],$siteId);
            $records=Db::name('agent_import_records')->where('batch_id',(int)$batch['id'])->where('entity_type','report_overview')->order('id asc')->select()->toArray(); if(!$records)continue;
            foreach($records as $record){ $payload=json_decode((string)$record['payload'],true); $sourceRows=$payload['response']['data']['rl']??[]; if(!is_array($sourceRows))continue;
            foreach($sourceRows as $source){
                if(!is_array($source))continue; $issue=trim((string)($source['dn']??$source['issue']??'')); $external=trim((string)($source['bli']??$source['bn']??''));
                $key=$issue.'|'.$external; if($external!==''&&isset($seen[$key]))continue; if($external!=='')$seen[$key]=true;
                $stamp=(int)($source['at']??0); if($stamp>20000000000)$stamp=(int)floor($stamp/1000); $placed=$stamp>0?date('Y-m-d H:i:s',$stamp):((string)$batch['from_date'].' 00:00:00'); $day=substr($placed,0,10); if($day<$from||$day>$to)continue;
                $name=trim((string)($source['an']??'外部会员')); $pseudo=-abs(crc32($siteId.'|'.$name)); $amount=(float)($source['am']??0); $count=(int)($source['bc']??0);
                // iw=1 is a withdrawn row and wt is its withdrawal timestamp,
                // not winnings. Exclude it from the betting report and never
                // feed that timestamp into the monetary columns.
                if((int)($source['iw']??0)===1||$amount<=0||$count<=0)continue;
                // `mi`/`ai` is the external member id. Resolve it to the
                // local organization created from that member's `pi` parent;
                // only old batches without a tree snapshot fall back to the
                // selected target organization.
                $organizationId=(int)($organizationMap[trim((string)($source['mi']??''))]??$batch['target_organization_id']);
                if($allowed!==[]&&!in_array($organizationId,$allowed,true))continue;
                $out[]=['id'=>0,'user_id'=>$pseudo,'username'=>$name,'organization_id'=>$organizationId,'share_rate'=>0,'issue_no'=>$issue,'number_text'=>'0','amount'=>$amount,'odds'=>null,'win_amount'=>0,'rebate'=>0,'placed_at'=>$placed,'lottery'=>'福彩3D','drop_odds'=>null,'import_bet_count'=>$count];
            }}
        }
        return $out;
    }

    /** @param array<int,array<int,array<string,mixed>>> $cache */
    private function organizationChain(int $siteId,int $organizationId,array &$cache): array
    {
        if ($organizationId<1) return [];
        if (array_key_exists($organizationId,$cache)) return $cache[$organizationId];
        return $cache[$organizationId]=OrganizationHierarchy::shareChain($siteId,$organizationId);
    }

    private function memberRows(array $rows): array
    {
        $groups=[];
        foreach($rows as $row){
            $key=(string)($row['user_id']??$row['username']??'');
            if(!isset($groups[$key]))$groups[$key]=['member'=>(string)($row['username']??'会员'),'rows'=>[]];
            $groups[$key]['rows'][]=$row;
        }
        $list=[];
        foreach($groups as $group)$list[]=['member'=>$group['member'],'summary'=>$this->aggregate($group['rows'])];
        usort($list,static fn(array $a,array $b):int=>strcmp((string)$a['member'],(string)$b['member']));
        return $list;
    }

    /**
     * Flat member view: one row per member under the current node, each
     * carrying the ancestor organization names shown as chain columns.
     * Column layout depends on the current node's level — a director sees
     * 会员/小股东/大股东/总监, a shareholder sees 会员/总代理/小股东/大股东,
     * and so on, always the two levels below the current node plus itself
     * (the bottom agent level pads one parent for context).
     */
    private function memberList(AgentReportScope $scope,array $session,array $rows): array
    {
        // Every member under the current subtree is a row, even when it has
        // no bets in the range — the chain columns must not drop members
        // just because the selected period is empty for them.
        $userQuery=Db::name('site_users')->where('site_id',$scope->siteId())->whereNull('deleted_at')->field('id,username,organization_id');
        OrganizationHierarchy::applyUserScope($userQuery,$session,'id');
        $groups=[];
        foreach($userQuery->select()->toArray() as $user)
            $groups[(int)$user['id']]=['id'=>(int)$user['id'],'type'=>'member','member'=>(string)$user['username'],'organization_id'=>(int)($user['organization_id']??0),'rows'=>[]];
        foreach($rows as $row){
            $uid=(int)($row['user_id']??0);
            if(!isset($groups[$uid]))$groups[$uid]=['id'=>$uid,'type'=>'member','member'=>(string)($row['username']??'会员'),'organization_id'=>(int)($row['organization_id']??0),'rows'=>[]];
            $groups[$uid]['rows'][]=$row;
        }
        $levelKeys=$this->chainLevels($scope->currentLevel());
        $siteId=$scope->siteId();
        $chainCache=[];
        $list=[];
        foreach($groups as $group){
            $chain=[];
            $orgId=$group['organization_id'];
            if($orgId>0){
                if(!isset($chainCache[$orgId])){
                    $map=[];
                    foreach(OrganizationHierarchy::shareChain($siteId,$orgId) as $node)$map[(string)($node['level']??'')]=$node;
                    $chainCache[$orgId]=$map;
                }
                foreach($levelKeys as $level){
                    $key=$level['key']; if($key==='member')continue;
                    $node=$chainCache[$orgId][$key]??null;
                    $chain[$key]=$node?['id'=>(int)$node['id'],'name'=>(string)$node['name']]:null;
                }
            }
            $list[]=['id'=>$group['id'],'type'=>'member','member'=>$group['member'],'chain'=>$chain,'issue_count'=>AgentReportScope::issueCount($group['rows']),'summary'=>$this->aggregate($group['rows'])];
        }
        usort($list,static fn(array $a,array $b):int=>strcmp($a['member'],$b['member'])?:($a['id']<=>$b['id']));
        return ['list'=>$list,'chain_levels'=>$levelKeys,'row_label'=>'会员','issue_count'=>AgentReportScope::issueCount($rows)];
    }

    /**
     * Ordered column levels for the flat member view: member column first,
     * then the two organization levels directly below the current node
     * (lowest first), then the current level. Bottom levels pad upward so
     * an agent still shows 会员/代理/总代理.
     */
    private function chainLevels(string $currentLevel): array
    {
        $rank=['member'=>0,'agent'=>1,'general_agent'=>2,'small_shareholder'=>3,'shareholder'=>4,'director'=>5];
        $byRank=array_flip($rank);
        $cur=$rank[$currentLevel]??5;
        $below=[];
        for($r=$cur-1;$r>=1&&count($below)<2;$r--)$below[]=$r;
        $cols=array_merge([0],array_reverse($below),[$cur]);
        for($next=$cur+1;count($cols)<3&&$next<=5;$next++)$cols[]=$next;
        return array_map(static function(int $r)use($byRank):array{
            $key=$byRank[$r];
            return ['key'=>$key,'label'=>OrganizationHierarchy::LABELS[$key]??'会员'];
        },$cols);
    }

    /**
     * Return only organization levels that are actually related to the
     * current account: all descendants, the current level, and all ancestors.
     * The client uses the relation to choose the appropriate column group.
     * @return array<int,array{key:string,label:string,relation:string}>
     */
    private function reportLevels(array $session): array
    {
        $siteId=(int)($session['site_id']??0); $currentId=(int)($session['organization_id']??0);
        $current=$currentId>0?Db::name('organization_nodes')->where('id',$currentId)->where('site_id',$siteId)->whereNull('deleted_at')->find():null;
        if(!$current) $current=OrganizationHierarchy::rootForSite($siteId);
        if(!$current) return [];
        $currentLevel=(string)$current['level'];
        $nodes=[];
        $descendantIds=OrganizationHierarchy::descendantIds((int)$current['id']);
        if($descendantIds) $nodes=Db::name('organization_nodes')->whereIn('id',$descendantIds)->where('status',1)->whereNull('deleted_at')->select()->toArray();
        // Keep the current node and its direct parent only. Descendants are
        // fully included above, but a report must not expose the parent's
        // parent (or unrelated shareholder/director levels) to this account.
        $nodes[]=$current;
        $parentId=(int)($current['parent_id']??0);
        if($parentId>0){
            $parent=Db::name('organization_nodes')->where('id',$parentId)->where('site_id',$siteId)->where('status',1)->whereNull('deleted_at')->find();
            if($parent) $nodes[]=$parent;
        }
        $rank=array_flip(array_keys(OrganizationHierarchy::LABELS));
        $currentRank=(int)($rank[$currentLevel]??0);
        $levels=[];$seen=[];
        foreach($nodes as $node){
            $key=(string)($node['level']??''); if($key===''||isset($seen[$key])) continue; $seen[$key]=true;
            $nodeRank=(int)($rank[$key]??$currentRank);
            $relation=$nodeRank>$currentRank?'downline':($nodeRank===$currentRank?'self':'upline');
            $levels[]=['key'=>$key,'label'=>OrganizationHierarchy::LABELS[$key]??$key,'relation'=>$relation];
        }
        usort($levels,static function(array $a,array $b)use($rank):int{return ((int)($rank[$b['key']]??0))<=>((int)($rank[$a['key']]??0));});
        return $levels;
    }

    private function aggregate(array $rows): array
    {
        $total=['bet_count'=>0,'amount'=>0.0,'win_amount'=>0.0,'water'=>0.0,'member_profit'=>0.0,'share_amount'=>0.0,'share_profit'=>0.0,'offline_water'=>0.0,'agent_water'=>0.0,'agent_profit'=>0.0,'platform_amount'=>0.0,'platform_profit'=>0.0,'share_base'=>0.0,'share_rate'=>0.0,'water_rate'=>0.0,'has_share'=>0];
        $amountKeys=['bet_count','amount','win_amount','water','member_profit','share_amount','share_profit','offline_water','agent_water','agent_profit','platform_amount','platform_profit','share_base'];
        $levelTotals=[];
        foreach($rows as $row) {
            $metrics=is_array($row['metrics']??null)?$row['metrics']:[];
            foreach($amountKeys as $key) $total[$key]+=$metrics[$key]??0;
            foreach((array)($metrics['levels']??[]) as $levelKey=>$levelMetric) {
                if(!isset($levelTotals[$levelKey])) $levelTotals[$levelKey]=['amount'=>0.0,'share_base'=>0.0];
                $levelTotals[$levelKey]['amount']+=(float)($levelMetric['amount']??0);
                $levelTotals[$levelKey]['share_base']+=(float)($levelMetric['share_base']??0);
            }

            // Rates are attributes of the report scope, not monetary values.
            // Never add them once per bet detail: 102 details must still use
            // 0.085, rather than the erroneous 0.085 * 102 = 8.67.
            foreach(['share_rate','water_rate'] as $key) {
                $rate=(float)($metrics[$key]??0);
                if($rate>0) $total[$key]=$rate;
            }
            if((int)($metrics['has_share']??0)===1) $total['has_share']=1;
        }
        // Rebuild all organization-side figures from the grouped member net
        // P/L. This prevents mixed winning/losing details from inflating the
        // occupation amount (e.g. 1328.20 vs the correct 1200.76).
        // share_base already contains each line's signed allocation; summing
        // it preserves per-member rates without multiplying one aggregate by
        // a repeated rate value.
        $base=(float)$total['share_base']; $occupation=abs($base);
        $water=$occupation*(float)$total['water_rate']; $hasShare=(int)$total['has_share']>0;
        $direction=$total['member_profit']>0?-1.0:1.0;
        $shareProfit=$direction*($occupation+$water);
        $total['share_base']=$base; $total['share_amount']=$occupation; $total['share_profit']=$shareProfit;
        $total['agent_water']=$water; $total['offline_water']=0.0; $total['agent_profit']=$shareProfit;
        $total['platform_profit']=-$total['member_profit']-$shareProfit;
        // Per-level columns: 总投 is the member stake that flowed through a
        // chain node at that level; 盈亏 is the mirror of the member P/L the
        // level actually booked (so a 100% agent shows +1022 when the member
        // lost 1022); 赚水 is that level's occupation times the site rate.
        $total['levels']=[];
        foreach($levelTotals as $levelKey=>$levelMetric) {
            $levelBase=(float)$levelMetric['share_base'];
            $total['levels'][$levelKey]=[
                'amount'=>$this->number((float)$levelMetric['amount']),
                'water'=>$this->number(abs($levelBase)*(float)$total['water_rate']),
                'profit'=>$this->number(-$levelBase),
            ];
        }
        unset($total['share_base'],$total['share_rate'],$total['water_rate'],$total['has_share']);
        foreach($total as $key=>$value) if($key!=='bet_count'&&$key!=='levels') $total[$key]=$this->number((float)$value);
        return $total;
    }

    private function dates(Request $request,array $session=[]): array
    {
        $from=trim((string)$request->param('from',date('Y-m-d'))); $to=trim((string)$request->param('to',date('Y-m-d')));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) throw new \InvalidArgumentException('日期格式不正确');
        if($from>$to) [$from,$to]=[$to,$from];
        if(strtotime($to)-strtotime($from)>366*86400) throw new \InvalidArgumentException('报表查询范围不能超过一年');
        if(!empty($session['is_subaccount'])&&!empty($session['report_limit_enabled'])) {
            $limitFrom=$this->issueDay((string)($session['report_from_issue']??'')); $limitTo=$this->issueDay((string)($session['report_to_issue']??''));
            if($limitFrom&&$limitTo) { if($limitFrom>$limitTo)[$limitFrom,$limitTo]=[$limitTo,$limitFrom]; $from=max($from,$limitFrom); $to=min($to,$limitTo); if($from>$to) throw new \InvalidArgumentException('查询范围超出子账号报表期限'); }
        }
        return [$from,$to];
    }

    private function issueDay(string $issue): string { return $issue===''?'':(string)(Db::name('lottery_histories')->where('code',$issue)->order('draw_day desc')->value('draw_day')?:''); }

    private function lotteries(Request $request): array
    {
        $input=$request->param('lotteries',[]); if($input==='__none__') return ['__none__']; if(is_string($input)) $input=array_filter(explode(',',$input));
        if(!is_array($input)) return [];
        return array_values(array_intersect(['福彩3D','排列三'],array_map('strval',$input)));
    }

    private function issueDate(array $rows): string
    {
        $date=''; foreach($rows as $row) { $value=substr((string)$row['placed_at'],0,10); if($value>$date) $date=$value; } return $date;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format(abs($value)<0.005?0:$value,2,'.',''),'0'),'.')?:'0';
    }

    private function waterRate(int $siteId): float
    {
        $settings=Db::name('sites')->where('id',$siteId)->value('settings');
        $settings=is_string($settings)?json_decode($settings,true):(is_array($settings)?$settings:[]);
        return max(0,min(1,(float)($settings['water_rate']??$settings['dark_water_rate']??0.085)));
    }
}
