<?php
declare(strict_types=1);

namespace app\controller;

use app\service\OrganizationHierarchy;
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
        $session=$this->session($request); $siteId=(int)$session['site_id'];
        try { [$from,$to]=$this->dates($request,$session); } catch (\InvalidArgumentException $e) { return $this->reply(null,$e->getMessage(),422); } $lotteries=$this->lotteries($request);
        $rows=$this->rows($session,$from,$to,$lotteries);
        return $this->reply(['summary'=>$this->aggregate($rows),'list'=>$this->memberRows($rows),'report_levels'=>$this->reportLevels($session),'from'=>$from,'to'=>$to,'lotteries'=>$lotteries]);
    }

    public function monthly(Request $request): \think\response\Json
    {
        $session=$this->session($request); $siteId=(int)$session['site_id'];
        try { [$from,$to]=$this->dates($request,$session); } catch (\InvalidArgumentException $e) { return $this->reply(null,$e->getMessage(),422); } $lotteries=$this->lotteries($request);
        $rows=$this->rows($session,$from,$to,$lotteries); $groups=[];
        foreach($rows as $row) {
            $issue=(string)$row['issue_no'];
            if(!isset($groups[$issue])) $groups[$issue]=[];
            $groups[$issue][]=$row;
        }
        $list=[];
        foreach($groups as $issue=>$group) $list[]=['issue_no'=>$issue,'draw_date'=>$this->issueDate($group),'summary'=>$this->aggregate($group)];
        usort($list,static fn(array $a,array $b): int=>strcmp((string)$b['issue_no'],(string)$a['issue_no']));
        return $this->reply(['list'=>$list,'total'=>$this->aggregate($rows),'report_levels'=>$this->reportLevels($session),'from'=>$from,'to'=>$to,'lotteries'=>$lotteries]);
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
        $query=Db::name('bet_details')->alias('d')
            ->join('bet_records r','r.id=d.bet_record_id')
            ->join('site_users u','u.id=d.user_id')
            ->leftJoin('user_stop_drops s','s.bet_detail_id=d.id')
            ->where('d.site_id',$siteId)->where('u.site_id',$siteId)->whereNull('u.deleted_at')->where('d.placed_at','>=',$from.' 00:00:00')->where('d.placed_at','<=',$to.' 23:59:59')
            ->where('r.status','<>','refunded');
        OrganizationHierarchy::applyUserScope($query,$session,'d.user_id');
        if($lotteries!==[]) $query->whereIn('s.lottery',$lotteries);
        $rows=$query->field('d.id,d.user_id,u.username,u.organization_id,u.interception_rate AS share_rate,d.issue_no,d.number_text,d.amount,d.odds,d.win_amount,d.rebate,d.placed_at,s.lottery,s.drop_odds')->select()->toArray();
        if($rows===[]) return [];
        $detailIds=array_map(static fn(array $row): int=>(int)$row['id'],$rows);
        $interceptions=Db::name('agent_interceptions')->whereIn('bet_detail_id',$detailIds)->whereNull('released_at')->field('bet_detail_id,SUM(intercepted_amount) AS intercepted_amount,SUM(bet_amount) AS intercepted_base')->group('bet_detail_id')->select()->toArray();
        $map=[]; foreach($interceptions as $row) $map[(int)$row['bet_detail_id']]=$row;
        foreach($rows as &$row) {
            $amount=(float)$row['amount']; $win=(float)$row['win_amount']; $rebate=(float)$row['rebate'];
            $intercepted=(float)($map[(int)$row['id']]['intercepted_amount']??0);
            // Occupation is based on the member's own P/L and configured
            // occupation percentage. It is independent of how much capacity
            // was actually intercepted. The single configured water amount
            // is based on the occupation amount.
            $memberProfit=$win+$rebate-$amount;
            // Use the same remaining-profit allocation as settlement: the
            // nearest organization receives its percentage first, and only
            // the remainder is passed to its parent.
            $chain=$this->organizationChain($siteId,(int)($row['organization_id']??0),$chainCache);
            if ($chain===[] && (float)($row['share_rate']??0)>0) {
                // Legacy members without an organization retain their direct
                // historical percentage in the report.
                // Use a non-root sentinel parent so the legacy percentage is
                // applied instead of being promoted to the mandatory 100%
                // root allocation by SequentialProfitShare.
                $chain=[['id'=>0,'parent_id'=>1,'level'=>'agent','share_rate'=>(float)$row['share_rate']]];
            }
            $allocations=SequentialProfitShare::allocate($memberProfit,$chain,$siteCap);
            $allocationAmount=(float)($allocations[0]['amount']??0);
            // Occupation amount is always displayed as a positive principal.
            // The sign belongs only to occupation P/L: a positive member P/L
            // means the member won and the organization must pay it out.
            $occupationAmount=abs($allocationAmount);
            $hasShare=(float)($allocations[0]['share_rate']??0)>0;
            $water=$occupationAmount*$waterRate;
            // The single site-wide 明水 is part of occupation P/L. There is no
            // separate offline/dark-water stream.
            $direction=$memberProfit>0?-1.0:1.0;
            // Confirmed formula: water money = occupation amount × 0.085;
            // occupation P/L is the signed occupation amount plus that water.
            $shareProfit=$direction*($occupationAmount+$water);
            $agentProfit=$shareProfit;
            $numbers=preg_split('/[\s,，]+/u',trim((string)$row['number_text']),-1,PREG_SPLIT_NO_EMPTY)?:[];
            $houseProfit=-$memberProfit;
            $row['metrics']=['bet_count'=>max(1,count($numbers)),'amount'=>$amount,'win_amount'=>$win,'water'=>$rebate,'member_profit'=>$memberProfit,'share_amount'=>$occupationAmount,'share_profit'=>$shareProfit,'offline_water'=>0.0,'agent_water'=>$water,'agent_profit'=>$agentProfit,'platform_amount'=>max(0,$amount-$intercepted),'platform_profit'=>$houseProfit-$shareProfit,
                // Hidden aggregation inputs: occupation is calculated on the
                // member's net P/L after grouping, never by summing absolute
                // P/L for individual bet lines.
                'share_base'=>$allocationAmount,'share_rate'=>(float)($allocations[0]['share_rate']??0),'water_rate'=>$waterRate,'has_share'=>$hasShare?1:0];
        }
        unset($row); return $rows;
    }

    /** @param array<int,array<int,array<string,mixed>>> $cache */
    private function organizationChain(int $siteId,int $organizationId,array &$cache): array
    {
        if ($organizationId<1) return [];
        if (array_key_exists($organizationId,$cache)) return $cache[$organizationId];
        $chain=[];$current=$organizationId;$visited=[];
        while($current>0&&!in_array($current,$visited,true)) {
            $visited[]=$current;
            $node=Db::name('organization_nodes')->where('id',$current)->where('site_id',$siteId)->where('status',1)->whereNull('deleted_at')->find();
            if(!$node) break;
            $share=Db::name('organization_profit_shares')
                ->where('child_organization_id',(int)$node['id'])
                ->where('parent_organization_id',(int)$node['parent_id'])
                ->where('status',1)->find();
            $node['share_rate']=$share?(float)$share['share_rate']:0.0;
            $chain[]=$node;
            $current=(int)$node['parent_id'];
        }
        return $cache[$organizationId]=$chain;
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
        foreach($rows as $row) {
            $metrics=is_array($row['metrics']??null)?$row['metrics']:[];
            foreach($amountKeys as $key) $total[$key]+=$metrics[$key]??0;

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
        unset($total['share_base'],$total['share_rate'],$total['water_rate'],$total['has_share']);
        foreach($total as $key=>$value) if($key!=='bet_count') $total[$key]=$this->number((float)$value);
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
