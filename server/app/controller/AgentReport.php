<?php
declare(strict_types=1);

namespace app\controller;

use app\service\OrganizationHierarchy;
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
        return $this->reply(['summary'=>$this->aggregate($rows),'list'=>$this->memberRows($rows),'from'=>$from,'to'=>$to,'lotteries'=>$lotteries]);
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
        return $this->reply(['list'=>$list,'total'=>$this->aggregate($rows),'from'=>$from,'to'=>$to,'lotteries'=>$lotteries]);
    }

    public function issues(Request $request): \think\response\Json
    {
        $session=$this->session($request); $siteId=(int)$session['site_id']; $lottery=trim((string)$request->param('lottery',''));
        $query=Db::name('lottery_histories')->alias('h')->join('lotteries l','l.id=h.lottery_id')->join('site_lotteries sl','sl.lottery_id=l.id')
            ->where('sl.site_id',$siteId)->where('h.draw_day','>=',date('Y-m-01'))->where('h.draw_day','<=',date('Y-m-t'));
        if($lottery!=='') $query->where('l.name',$lottery);
        $configuredLimit=(int)Db::name('settings')->where('site_id',$siteId)->where('key','draw_history_limit')->value('value');
        $drawHistoryLimit=$configuredLimit>0?min(200,$configuredLimit):80;
        $items=$query->field('h.code AS issue_no,h.draw_day AS date')->order('h.draw_day desc')->order('h.code desc')->limit($drawHistoryLimit)->select()->toArray();
        $seen=[]; $list=[]; foreach($items as $row) { $issue=(string)$row['issue_no']; if(isset($seen[$issue])) continue; $seen[$issue]=true; $list[]=['issue_no'=>$issue,'date'=>(string)$row['date']]; }
        return $this->reply(['list'=>$list]);
    }

    private function rows(array $session,string $from,string $to,array $lotteries): array
    {
        [$darkWaterRate,$brightWaterRate]=$this->waterRates((int)$session['site_id']);
        $query=Db::name('bet_details')->alias('d')
            ->join('bet_records r','r.id=d.bet_record_id')
            ->join('site_users u','u.id=d.user_id')
            ->leftJoin('user_stop_drops s','s.bet_detail_id=d.id')
            ->where('d.site_id',(int)$session['site_id'])->where('u.site_id',(int)$session['site_id'])->whereNull('u.deleted_at')->where('d.placed_at','>=',$from.' 00:00:00')->where('d.placed_at','<=',$to.' 23:59:59')
            ->where('r.status','<>','refunded');
        OrganizationHierarchy::applyUserScope($query,$session,'d.user_id');
        if($lotteries!==[]) $query->whereIn('s.lottery',$lotteries);
        $rows=$query->field('d.id,d.user_id,u.username,d.issue_no,d.number_text,d.amount,d.odds,d.win_amount,d.rebate,d.placed_at,s.lottery,s.drop_odds')->select()->toArray();
        if($rows===[]) return [];
        $detailIds=array_map(static fn(array $row): int=>(int)$row['id'],$rows);
        $interceptions=Db::name('agent_interceptions')->whereIn('bet_detail_id',$detailIds)->whereNull('released_at')->field('bet_detail_id,SUM(intercepted_amount) AS intercepted_amount,SUM(bet_amount) AS intercepted_base')->group('bet_detail_id')->select()->toArray();
        $map=[]; foreach($interceptions as $row) $map[(int)$row['bet_detail_id']]=$row;
        foreach($rows as &$row) {
            $amount=(float)$row['amount']; $win=(float)$row['win_amount']; $rebate=(float)$row['rebate'];
            $intercepted=(float)($map[(int)$row['id']]['intercepted_amount']??0);
            // Occupation is based on the member's own P/L and configured
            // occupation percentage. It is independent of how much capacity
            // was actually intercepted. Dark water is based on the full bet;
            // bright water is based on the occupation amount.
            $memberProfit=$win+$rebate-$amount; $shareRate=max(0,min(100,(float)($row['share_rate']??0))); $occupationAmount=$memberProfit*$shareRate/100;
            $darkWater=$amount*$darkWaterRate; $brightWater=$occupationAmount*$brightWaterRate; $houseProfit=-$memberProfit; $shareProfit=$occupationAmount; $agentProfit=$occupationAmount+$darkWater+$brightWater;
            $numbers=preg_split('/[\s,，]+/u',trim((string)$row['number_text']),-1,PREG_SPLIT_NO_EMPTY)?:[];
            $row['metrics']=['bet_count'=>max(1,count($numbers)),'amount'=>$amount,'win_amount'=>$win,'water'=>$rebate,'member_profit'=>$memberProfit,'share_amount'=>$occupationAmount,'share_profit'=>$shareProfit,'offline_water'=>$darkWater,'agent_water'=>$brightWater,'agent_profit'=>$agentProfit,'platform_amount'=>max(0,$amount-$intercepted),'platform_profit'=>$houseProfit-$occupationAmount-$darkWater-$brightWater];
        }
        unset($row); return $rows;
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

    private function aggregate(array $rows): array
    {
        $total=['bet_count'=>0,'amount'=>0.0,'win_amount'=>0.0,'water'=>0.0,'member_profit'=>0.0,'share_amount'=>0.0,'share_profit'=>0.0,'offline_water'=>0.0,'agent_water'=>0.0,'agent_profit'=>0.0,'platform_amount'=>0.0,'platform_profit'=>0.0];
        foreach($rows as $row) foreach($total as $key=>$value) $total[$key]+=$row['metrics'][$key]??0;
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

    private function waterRates(int $siteId): array
    {
        $settings=Db::name('sites')->where('id',$siteId)->value('settings');
        $settings=is_string($settings)?json_decode($settings,true):(is_array($settings)?$settings:[]);
        return [max(0,min(1,(float)($settings['dark_water_rate']??0.085))),max(0,min(1,(float)($settings['bright_water_rate']??0.012)))];
    }
}
