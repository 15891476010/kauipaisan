<?php
declare(strict_types=1);

namespace app\controller;

use app\service\OrganizationHierarchy;
use think\Request;
use think\facade\Cache;
use think\facade\Db;

final class AgentBusiness
{
    private function reply(mixed $data=null, string $message='ok', int $code=0): \think\response\Json
    {
        return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]);
    }

    private function session(Request $request): array
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token !== '' ? Cache::get('token:'.$token) : null;
        if (!is_array($session) || ($session['scope']??'') !== 'agent') throw new \RuntimeException('未登录或登录已过期');
        if ((int)($session['site_id']??0) < 1) throw new \RuntimeException('当前代理未绑定站点');
        return $session;
    }

    private function page(Request $request, int $default=40): array
    {
        return [max(1,(int)$request->param('page',1)),min(100,max(1,(int)$request->param('page_size',$default)))];
    }

    private function normalizeIssue(string $value): string
    {
        $value=trim($value);
        return preg_match('/\\(([^)]+)\\)$/',$value,$matches) ? trim((string)$matches[1]) : $value;
    }

    private function issueRange(mixed $query, Request $request, string $field): void
    {
        $from=$this->normalizeIssue((string)$request->param('from_issue',''));
        $to=$this->normalizeIssue((string)$request->param('to_issue',''));
        if ($from !== '' && $to !== '' && $from > $to) [$from,$to]=[$to,$from];
        if ($from !== '') $query->where($field,'>=',$from);
        if ($to !== '') $query->where($field,'<=',$to);
    }

    private function timeRange(mixed $query, Request $request, string $field): void
    {
        $from=trim((string)$request->param('from',''));
        $to=trim((string)$request->param('to',''));
        if ($from !== '') $query->where($field,'>=',preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$from) ? $from.' 00:00:00' : $from);
        if ($to !== '') $query->where($field,'<=',preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$to) ? $to.' 23:59:59' : $to);
    }

    private function money(mixed $value): string
    {
        return number_format((float)$value,2,'.','');
    }

    /** Main order number: YYMMDDHHMMSS plus the two-digit submission suffix. */
    private function orderNumber(array $row): string
    {
        $explicit=trim((string)($row['submission_order_no']??$row['order_no']??''));
        if ($explicit!=='') return $explicit;
        $parent=(int)($row['submission_id']??0);
        if ($parent<1) $parent=(int)($row['bet_record_id']??$row['id']??0);
        $stamp=preg_replace('/\D+/','',(string)($row['placed_at']??''))??'';
        $stamp=substr($stamp,2,12);
        if (strlen($stamp)<12) $stamp=str_pad($stamp,12,'0');
        return $stamp.str_pad((string)($parent%100),2,'0',STR_PAD_LEFT);
    }

    private function accountingQuery(Request $request, array $session): mixed
    {
        $siteId=(int)$session['site_id'];
        $tenantId=(int)($session['tenant_id']??0);
        if ($tenantId < 1) $tenantId=(int)Db::name('sites')->where('id',$siteId)->value('tenant_id');
        if ($tenantId < 1) throw new \RuntimeException('当前代理租户信息无效');

        $query=Db::name('bet_details')->alias('d')
            ->join('bet_records r','r.id=d.bet_record_id')
            ->join('site_users u','u.id=d.user_id')
            ->leftJoin('user_stop_drops s','s.bet_detail_id=d.id')
            ->where('d.tenant_id',$tenantId)
            ->where('d.site_id',$siteId)
            ->where('r.tenant_id',$tenantId)
            ->where('r.site_id',$siteId)
            ->where('u.tenant_id',$tenantId)
            ->where('u.site_id',$siteId)
            ->where('d.status','<>','refunded')
            ->whereNull('u.deleted_at');
        OrganizationHierarchy::applyUserScope($query,$session,'d.user_id');

        $account=trim((string)$request->param('account',''));
        if ($account !== '') $query->whereLike('u.username','%'.$account.'%');
        $this->issueRange($query,$request,'d.issue_no');
        $this->timeRange($query,$request,'d.placed_at');

        $lotteryId=(int)$request->param('lottery_id',0);
        if ($lotteryId > 0) {
            $lotteryName=Db::name('lotteries')->alias('l')
                ->join('site_lotteries sl','sl.lottery_id=l.id')
                ->where('l.id',$lotteryId)->where('l.tenant_id',$tenantId)
                ->where('sl.tenant_id',$tenantId)->where('sl.site_id',$siteId)
                ->whereNull('l.deleted_at')->value('l.name');
            if (!$lotteryName) $query->whereRaw('1=0');
            else $query->where('s.lottery',(string)$lotteryName);
        }

        $lotteries=$request->param('lotteries',null);
        if ($lotteries !== null) {
            $requested=array_values(array_filter(array_map('trim',explode(',',(string)$lotteries))));
            $available=Db::name('lotteries')->alias('l')
                ->join('site_lotteries sl','sl.lottery_id=l.id')
                ->where('l.tenant_id',$tenantId)->where('sl.tenant_id',$tenantId)
                ->where('sl.site_id',$siteId)->whereNull('l.deleted_at')->column('l.name');
            $selected=array_values(array_intersect($requested,array_map('strval',$available)));
            if (!$selected) $query->whereRaw('1=0');
            else $query->whereIn('s.lottery',$selected);
        }
        return $query;
    }

    private function pageRows(array $rows, Request $request): array
    {
        [$page,$size]=$this->page($request,100);
        return [
            array_slice($rows,($page-1)*$size,$size),
            count($rows),
            $page,
            $size,
        ];
    }

    private function detailQuery(Request $request, array $session, bool $winningOnly=false): mixed
    {
        $siteId=(int)$session['site_id'];
        $query=Db::name('bet_details')->alias('d')
            ->join('bet_records r','r.id=d.bet_record_id')
            ->join('site_users u','u.id=d.user_id')
            ->leftJoin('user_stop_drops s','s.bet_detail_id=d.id')
            ->where('d.site_id',$siteId)
            ->where('r.site_id',$siteId)
            ->whereNull('u.deleted_at');
        if ((int)$request->param('include_refunded',0)!==1) $query->where('d.status','<>','refunded');
        OrganizationHierarchy::applyUserScope($query,$session,'d.user_id');

        $lotteryId=(int)$request->param('lottery_id',0);
        if ($lotteryId > 0) $query->join('lottery_histories lh','lh.code=d.issue_no AND lh.lottery_id='.$lotteryId);
        $account=trim((string)$request->param('account',''));
        if ($account !== '') $query->whereLike('u.username','%'.$account.'%');
        $number=trim((string)$request->param('number',''));
        if ($number !== '') $query->whereLike('d.number_text','%'.$number.'%');
        $recordId=(int)$request->param('record_id',0);
        if ($recordId > 0) $query->where('d.bet_record_id',$recordId);
        $category=trim((string)$request->param('category',''));
        if ($category !== '' && $category !== '所有') {
            $query->where(function ($nested) use ($category): void {
                $nested->where('s.play_type',$category)->whereOr('d.category',$category);
            });
        }
        $source=(string)$request->param('source','all');
        if ($source === 'quick') $query->whereNotNull('r.source_text');
        $this->issueRange($query,$request,'d.issue_no');
        $metric=(string)$request->param('metric','odds');
        $metric=in_array($metric,['odds','amount'],true)?$metric:'odds';
        $min=$request->param('min');
        $max=$request->param('max');
        if ($min !== null && $min !== '' && is_numeric($min)) $query->where('d.'.$metric,'>=',(float)$min);
        if ($max !== null && $max !== '' && is_numeric($max)) $query->where('d.'.$metric,'<=',(float)$max);
        if ($winningOnly) $query->where('d.status','won');
        return $query;
    }

    private function detailResponse(Request $request, bool $winningOnly=false): \think\response\Json
    {
        $session=$this->session($request);
        $siteSettings=Db::name('sites')->where('id',(int)$session['site_id'])->value('settings');
        $siteSettings=is_string($siteSettings)?json_decode($siteSettings,true):(is_array($siteSettings)?$siteSettings:[]);
        $waterRate=max(0,min(1,(float)($siteSettings['water_rate']??$siteSettings['dark_water_rate']??0.085)));
        $query=$this->detailQuery($request,$session,$winningOnly);
        $summary=(clone $query)->field('COALESCE(SUM(d.amount),0) total_amount,COALESCE(SUM(d.win_amount),0) win_amount,COUNT(d.id) total')->find() ?: [];
        [$page,$size]=$this->page($request);
        $sort=(string)$request->param('sort','desc')==='asc'?'asc':'desc';
        $rows=$query->field('d.id,d.bet_record_id,r.submission_id,d.issue_no,d.number_text,d.category,d.amount,d.odds,d.win_amount,d.rebate,d.status,d.placed_at,d.source_text,u.username,COALESCE(s.play_type,d.category) play_type,s.lottery,r.source_text record_source')
            ->order('d.placed_at',$sort)->order('d.id',$sort)->page($page,$size)->select()->toArray();
        foreach ($rows as &$row) {
            $amount=(float)$row['amount']; $rebate=(float)$row['rebate']; $win=(float)$row['win_amount'];
            $displayPlay=(string)($row['play_type']??''); $displaySource=(string)($row['source_text']??'');
            if (str_contains($displayPlay,'双飞') || str_contains($displaySource,'对子')) {
                $row['number_text']=preg_replace('/^0(?=\d{2}(?:飞)?$)/u','',(string)$row['number_text'])??(string)$row['number_text'];
            }
            $row['order_no']=$this->orderNumber($row);
            $row['amount']=number_format($amount,2,'.','');
            $row['odds']=$row['odds'] === null ? '-' : rtrim(rtrim(number_format((float)$row['odds'],4,'.',''),'0'),'.');
            $row['win_amount']=number_format($win,2,'.','');
            $row['downline_rebate']=number_format($rebate,2,'.','');
            // 实收下线按“下注金额 - 中奖金额 - 下线回水”计算，中奖时允许显示负数。
            $row['received_amount']=number_format($amount-$win-$rebate,2,'.','');
            $row['own_rebate']='0.00';
            $row['paid_upstream']=number_format(max(0,$amount-$rebate),2,'.','');
            $row['water']=number_format(0,2,'.','');
            $row['offline_rebate']=number_format(0,2,'.','');
            $row['source']='快录';
            $row['device']='网';
            $row['path']='会员 / '.$row['username'];
            $row['ticket']=(string)($row['record_source'] ?: $row['source_text'] ?: '');
        }
        return $this->reply([
            'list'=>$rows,
            'total'=>(int)($summary['total']??0),
            'total_amount'=>number_format((float)($summary['total_amount']??0),2,'.',''),
            'win_amount'=>number_format((float)($summary['win_amount']??0),2,'.',''),
            'page'=>$page,'page_size'=>$size,
        ]);
    }

    public function categories(Request $request): \think\response\Json
    {
        $session=$this->session($request);
        $siteId=(int)$session['site_id'];
        $tenantId=(int)($session['tenant_id']??0);
        $lotteryIds=Db::name('site_lotteries')->where('site_id',$siteId)->where('tenant_id',$tenantId)->column('lottery_id');
        $names=[];
        if ($lotteryIds) {
            $names=array_merge(
                Db::name('lottery_odds_categories')->whereIn('lottery_id',$lotteryIds)->where('status',1)->whereNull('deleted_at')->order('sort asc')->column('name'),
                Db::name('lottery_odds')->whereIn('lottery_id',$lotteryIds)->where('status',1)->whereNull('deleted_at')->order('sort asc')->column('name')
            );
        }
        $names=array_values(array_unique(array_filter(array_map('strval',$names))));
        return $this->reply(['list'=>$names]);
    }

    public function orderDetails(Request $request): \think\response\Json
    {
        return $this->detailResponse($request,false);
    }

    public function winningDetails(Request $request): \think\response\Json
    {
        return $this->detailResponse($request,true);
    }

    public function betRecords(Request $request): \think\response\Json
    {
        $session=$this->session($request); $siteId=(int)$session['site_id'];
        $query=Db::name('bet_records')->alias('r')->join('site_users u','u.id=r.user_id')
            ->where('r.site_id',$siteId)->whereNull('u.deleted_at');
        OrganizationHierarchy::applyUserScope($query,$session,'r.user_id');
        $lotteryId=(int)$request->param('lottery_id',0);
        if ($lotteryId > 0) $query->join('lottery_histories lh','lh.code=r.issue_no AND lh.lottery_id='.$lotteryId);
        $account=trim((string)$request->param('account',''));
        if ($account !== '') $query->whereLike('u.username','%'.$account.'%');
        $sourceText=trim((string)$request->param('source_text',''));
        if ($sourceText !== '') $query->where(function($nested)use($sourceText):void{$nested->whereLike('r.source_text','%'.$sourceText.'%')->whereOrLike('r.formatted_text','%'.$sourceText.'%');});
        $status=(string)$request->param('status','all');
        if ($status === 'won') $query->where('r.status','won');
        elseif ($status === 'unwon') $query->where('r.status','unwon');
        $this->issueRange($query,$request,'r.issue_no');
        $this->timeRange($query,$request,'r.placed_at');
        $total=(clone $query)->count();
        [$page,$size]=$this->page($request);
        $rows=$query->field('r.id,r.submission_id,r.issue_no,r.source_text,r.formatted_text,r.bet_count,r.amount,r.win_amount,r.status,r.sealed,r.placed_at,u.username')
            ->order('r.placed_at','desc')->order('r.id','desc')->page($page,$size)->select()->toArray();
        $ids=array_map('intval',array_column($rows,'id')); $wins=[];
        if ($ids) {
            foreach (Db::name('bet_details')->whereIn('bet_record_id',$ids)->where('status','won')->field('bet_record_id,COUNT(*) win_count')->group('bet_record_id')->select()->toArray() as $row) $wins[(int)$row['bet_record_id']]=(int)$row['win_count'];
        }
        foreach ($rows as &$row) {
            $row['order_no']=$this->orderNumber($row);
            $row['amount']=number_format((float)$row['amount'],2,'.','');
            $row['win_amount']=number_format((float)$row['win_amount'],2,'.','');
            $row['win_count']=$wins[(int)$row['id']]??0;
            $row['sealed_label']=(int)$row['sealed']===1?'已封盘':'未封盘';
        }
        return $this->reply(['list'=>$rows,'total'=>(int)$total,'page'=>$page,'page_size'=>$size]);
    }

    public function refunds(Request $request): \think\response\Json
    {
        $session=$this->session($request); $siteId=(int)$session['site_id'];
        $query=Db::name('bet_records')->alias('r')->join('site_users u','u.id=r.user_id')
            ->where('r.site_id',$siteId)->where('r.status','refunded')->whereNull('u.deleted_at');
        OrganizationHierarchy::applyUserScope($query,$session,'r.user_id');
        $lotteryId=(int)$request->param('lottery_id',0);
        if ($lotteryId > 0) $query->join('lottery_histories lh','lh.code=r.issue_no AND lh.lottery_id='.$lotteryId);
        $account=trim((string)$request->param('account',''));
        if ($account !== '') $query->whereLike('u.username','%'.$account.'%');
        $this->issueRange($query,$request,'r.issue_no');
        $total=(clone $query)->count(); [$page,$size]=$this->page($request);
        $rows=$query->field('r.id,r.issue_no,r.bet_count,r.amount,r.placed_at,r.refunded_at,u.username')
            ->order('r.refunded_at','desc')->order('r.id','desc')->page($page,$size)->select()->toArray();
        foreach ($rows as &$row) {
            $row['amount']=number_format((float)$row['amount'],2,'.','');
            $row['refunded_at']=$row['refunded_at'] ?: $row['placed_at'];
        }
        return $this->reply(['list'=>$rows,'total'=>(int)$total,'page'=>$page,'page_size'=>$size]);
    }

    public function ledger(Request $request): \think\response\Json
    {
        $session=$this->session($request);
        $type=(string)$request->param('type','contribution');
        $valid=['contribution','daily','monthly','daily_path','monthly_path'];
        if (!in_array($type,$valid,true)) return $this->reply(null,'分类账类型无效',422);

        $query=$this->accountingQuery($request,$session);
        if ($type === 'contribution') {
            $rows=$query
                ->field("u.id user_id,u.username,COALESCE(u.interception_rate,0) share_rate,COUNT(d.id) bet_count,COALESCE(SUM(d.amount),0) amount,COALESCE(SUM(d.rebate),0) rebate,COALESCE(SUM(d.win_amount),0) win_amount,COALESCE(SUM(d.amount-d.win_amount-d.rebate),0) gross_profit")
                ->group('u.id,u.username,u.interception_rate')
                ->order('gross_profit','desc')->order('u.id','asc')->select()->toArray();
            $contributionBase=0.0;
            foreach ($rows as &$row) {
                $amount=(float)$row['amount'];
                $profit=(float)$row['gross_profit'];
                $rate=max(0,min(100,(float)$row['share_rate']));
                $shareProfit=$profit*$rate/100;
                $row['share_amount']=$this->money($amount*$rate/100);
                $row['share_total_amount']=$this->money($amount);
                $row['share_total_profit']=$this->money($profit);
                $row['percentage_share_profit']=$this->money($shareProfit);
                $row['actual_share_profit']=$this->money($shareProfit);
                $row['share_percentage']=rtrim(rtrim(number_format($rate,4,'.',''),'0'),'.').'%';
                $contributionBase+=abs($shareProfit);
            }
            unset($row);
            foreach ($rows as &$row) {
                $value=(float)$row['actual_share_profit'];
                $row['contribution']=$contributionBase > 0 ? number_format(abs($value)*100/$contributionBase,2,'.','').'%' : '0.00%';
                $row['amount']=$this->money($row['amount']);
                $row['rebate']=$this->money($row['rebate']);
                $row['win_amount']=$this->money($row['win_amount']);
                $row['gross_profit']=$this->money($row['gross_profit']);
            }
            unset($row);
            [$list,$total,$page,$size]=$this->pageRows($rows,$request);
            return $this->reply(['list'=>$list,'total'=>$total,'page'=>$page,'page_size'=>$size]);
        }

        $category="COALESCE(NULLIF(s.play_type,''),NULLIF(d.category,''),'未分类')";
        $monthly=in_array($type,['monthly','monthly_path'],true);
        if ($monthly) {
            $rows=$query
                ->field("d.issue_no account,$category category,COUNT(d.id) bet_count,COALESCE(SUM(d.amount),0) total_bet,COALESCE(SUM(d.rebate),0) rebate,COALESCE(SUM(d.win_amount),0) win_amount,COALESCE(SUM(d.win_amount-d.amount+d.rebate),0) profit")
                ->group("d.issue_no,$category")->order('d.issue_no','desc')->order('category','asc')->select()->toArray();
        } else {
            $rows=$query
                ->field("u.username account,$category category,COUNT(d.id) bet_count,COALESCE(SUM(d.amount),0) total_bet,COALESCE(SUM(d.rebate),0) rebate,COALESCE(SUM(d.win_amount),0) win_amount,COALESCE(SUM(d.win_amount-d.amount+d.rebate),0) profit")
                ->group("u.id,u.username,$category")->order('u.username','asc')->order('category','asc')->select()->toArray();
        }
        $summary=['bet_count'=>0,'total_bet'=>0.0,'rebate'=>0.0,'win_amount'=>0.0,'profit'=>0.0];
        foreach ($rows as &$row) {
            $row['path']=$monthly ? (string)$row['account'] : '会员 / '.(string)$row['account'];
            $summary['bet_count']+=(int)$row['bet_count'];
            foreach (['total_bet','rebate','win_amount','profit'] as $field) {
                $summary[$field]+=(float)$row[$field];
                $row[$field]=$this->money($row[$field]);
            }
        }
        unset($row);
        foreach (['total_bet','rebate','win_amount','profit'] as $field) $summary[$field]=$this->money($summary[$field]);
        [$list,$total,$page,$size]=$this->pageRows($rows,$request);
        return $this->reply(['list'=>$list,'total'=>$total,'summary'=>$summary,'page'=>$page,'page_size'=>$size]);
    }

    public function reports(Request $request): \think\response\Json
    {
        $session=$this->session($request);
        $siteSettings=Db::name('sites')->where('id',(int)$session['site_id'])->value('settings');
        $siteSettings=is_string($siteSettings)?json_decode($siteSettings,true):(is_array($siteSettings)?$siteSettings:[]);
        $waterRate=max(0,min(1,(float)($siteSettings['water_rate']??$siteSettings['dark_water_rate']??0.085)));
        $type=(string)$request->param('type','summary');
        if (!in_array($type,['summary','monthly'],true)) return $this->reply(null,'报表类型无效',422);

        $query=$this->accountingQuery($request,$session);
        $label=$type === 'monthly' ? 'd.issue_no' : 'u.username';
        $group=$type === 'monthly' ? 'd.issue_no' : 'u.id,u.username';
        $rows=$query->field(
            "$label label,".
            "COUNT(d.id) bet_count,".
            "COALESCE(SUM(d.amount),0) total_bet,".
            "COALESCE(SUM(d.win_amount),0) total_win,".
            "COALESCE(SUM(d.rebate),0) total_rebate,".
            "COALESCE(SUM(d.win_amount-d.amount+d.rebate),0) member_profit,".
            "COALESCE(ABS(SUM((d.win_amount-d.amount+d.rebate)*COALESCE(u.interception_rate,0)/100)),0) agent_share_amount,".
            "COALESCE((CASE WHEN SUM(d.win_amount-d.amount+d.rebate)>0 THEN -1 ELSE 1 END) * (ABS(SUM((d.win_amount-d.amount+d.rebate)*COALESCE(u.interception_rate,0)/100)) + ABS(SUM((d.win_amount-d.amount+d.rebate)*COALESCE(u.interception_rate,0)/100))*".$waterRate."),0) agent_share_profit,".
            "0 offline_rebate,".
            "0 agent_total_rebate,".
            "COALESCE((CASE WHEN SUM(d.win_amount-d.amount+d.rebate)>0 THEN -1 ELSE 1 END) * (ABS(SUM((d.win_amount-d.amount+d.rebate)*COALESCE(u.interception_rate,0)/100)) + ABS(SUM((d.win_amount-d.amount+d.rebate)*COALESCE(u.interception_rate,0)/100))*".$waterRate."),0) agent_total_profit,".
            "COALESCE(SUM(d.amount*(1-COALESCE(u.interception_rate,0)/100)),0) master_total_bet,".
            "COALESCE(-SUM(d.win_amount-d.amount+d.rebate)-((CASE WHEN SUM(d.win_amount-d.amount+d.rebate)>0 THEN -1 ELSE 1 END) * (ABS(SUM((d.win_amount-d.amount+d.rebate)*COALESCE(u.interception_rate,0)/100)) + ABS(SUM((d.win_amount-d.amount+d.rebate)*COALESCE(u.interception_rate,0)/100))*".$waterRate.")),0) master_profit"
        )->group($group)->order($label,$type === 'monthly' ? 'desc' : 'asc')->select()->toArray();

        $moneyFields=['total_bet','total_win','total_rebate','member_profit','agent_share_amount','agent_share_profit','offline_rebate','agent_total_rebate','agent_total_profit','master_total_bet','master_profit'];
        $totals=['label'=>'合计','bet_count'=>0];
        foreach ($moneyFields as $field) $totals[$field]=0.0;
        foreach ($rows as &$row) {
            $totals['bet_count']+=(int)$row['bet_count'];
            foreach ($moneyFields as $field) {
                $totals[$field]+=(float)$row[$field];
                $row[$field]=$this->money($row[$field]);
            }
        }
        unset($row);
        foreach ($moneyFields as $field) $totals[$field]=$this->money($totals[$field]);
        [$list,$total,$page,$size]=$this->pageRows($rows,$request);
        return $this->reply(['list'=>$list,'totals'=>$totals,'total'=>$total,'page'=>$page,'page_size'=>$size]);
    }
}
