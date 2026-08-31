<?php
declare(strict_types=1);

namespace app\controller;

use app\service\OrganizationHierarchy;
use think\Request;
use think\facade\Cache;
use think\facade\Db;

final class AgentLedger
{
    private function reply(mixed $data=null,string $message='ok',int $code=0): \think\response\Json { return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]); }
    private function session(Request $request): array { $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));$session=$token!==''?Cache::get('token:'.$token):null;if(!is_array($session)||($session['scope']??'')!=='agent')throw new \RuntimeException('未登录或登录已过期');if((int)($session['site_id']??0)<1)throw new \RuntimeException('当前代理未绑定站点');return $session; }

    public function issues(Request $request): \think\response\Json
    {
        $session=$this->session($request);$lottery=$this->lottery($session,trim((string)$request->param('lottery','')));
        $configuredLimit=(int)Db::name('settings')->where('site_id',(int)$session['site_id'])->where('key','draw_history_limit')->value('value');
        $drawHistoryLimit=$configuredLimit>0?min(200,$configuredLimit):80;
        $rows=Db::name('lottery_histories')->where('lottery_id',(int)$lottery['id'])->field('code AS issue_no,draw_day AS date')->order('draw_day desc')->order('code desc')->limit($drawHistoryLimit)->select()->toArray();
        $allowed=$this->limitIssues(array_map(static fn(array $row): string=>(string)$row['issue_no'],$rows),$session,(int)$lottery['id']);
        if(count($allowed)!==count($rows))$rows=array_values(array_filter($rows,static fn(array $row): bool=>in_array((string)$row['issue_no'],$allowed,true)));
        return $this->reply(['list'=>$rows]);
    }

    public function index(Request $request): \think\response\Json
    {
        $session=$this->session($request);$view=trim((string)$request->param('view','contribution'));
        if(!in_array($view,['contribution','daily','monthly','daily_path','monthly_path'],true))throw new \InvalidArgumentException('分类账类型不正确');
        $lottery=$this->lottery($session,trim((string)$request->param('lottery','')));$issues=$this->issueRange($request,(int)$lottery['id'],$view);$issues=$this->limitIssues($issues,$session,(int)$lottery['id']);$details=$this->details($session,(string)$lottery['name'],$issues);
        $rows=$view==='contribution'?$this->contribution($details):$this->ledgerRows($details,$view,$session);
        return $this->reply(['list'=>$rows,'total'=>count($rows),'view'=>$view,'lottery'=>$lottery,'issues'=>$issues]);
    }

    private function lottery(array $session,string $name): array
    {
        $query=Db::name('lotteries')->alias('l')->join('site_lotteries sl','sl.lottery_id=l.id')->where('sl.site_id',(int)$session['site_id'])->where('l.status',1)->whereNull('l.deleted_at');if($name!=='')$query->where('l.name',$name);
        $row=$query->field('l.id,l.name,l.code')->order('l.sort asc')->order('l.id asc')->find();if(!$row)throw new \InvalidArgumentException('当前彩种不可用');return $row;
    }

    private function issueRange(Request $request,int $lotteryId,string $view): array
    {
        $from=trim((string)$request->param('from_issue',''));$to=trim((string)$request->param('to_issue',''));
        if($from===''&&$to===''){$latest=(string)Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('draw_day','<=',date('Y-m-d'))->order('draw_day desc')->order('code desc')->value('code');if(in_array($view,['daily','daily_path'],true))return $latest!==''?[$latest]:[];return array_values(array_unique(array_map('strval',Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('draw_day','>=',date('Y-m-01'))->where('draw_day','<=',date('Y-m-t'))->order('draw_day asc')->column('code'))));}
        if($from===''||$to==='')$from=$to=$from!==''?$from:$to;$fromDate=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('code',$from)->value('draw_day');$toDate=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('code',$to)->value('draw_day');if(!$fromDate||!$toDate)return[];if($fromDate>$toDate)[$fromDate,$toDate]=[$toDate,$fromDate];
        return array_values(array_unique(array_map('strval',Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('draw_day','>=',$fromDate)->where('draw_day','<=',$toDate)->order('draw_day asc')->column('code'))));
    }

    private function details(array $session,string $lottery,array $issues): array
    {
        if($issues===[])return[];$query=Db::name('bet_details')->alias('d')->join('bet_records r','r.id=d.bet_record_id')->join('site_users u','u.id=d.user_id')->leftJoin('user_stop_drops s','s.bet_detail_id=d.id')->where('d.site_id',(int)$session['site_id'])->whereIn('d.issue_no',$issues)->where('s.lottery',$lottery)->where('r.status','<>','refunded');OrganizationHierarchy::applyUserScope($query,$session,'d.user_id');$rows=$query->field('d.id,d.user_id,u.username,u.interception_rate AS member_share_rate,d.issue_no,d.category,d.number_text,d.amount,d.win_amount,d.rebate,s.drop_odds')->select()->toArray();if($rows===[])return[];
        $settings=Db::name('sites')->where('id',(int)$session['site_id'])->value('settings');$settings=is_string($settings)?json_decode($settings,true):(is_array($settings)?$settings:[]);$darkRate=max(0,min(1,(float)($settings['dark_water_rate']??0.085)));$brightRate=max(0,min(1,(float)($settings['bright_water_rate']??0.012)));
        $ids=array_map(static fn(array $row):int=>(int)$row['id'],$rows);$allocations=Db::name('agent_interceptions')->whereIn('bet_detail_id',$ids)->whereNull('released_at')->field('bet_detail_id,SUM(requested_amount) requested,SUM(intercepted_amount) intercepted,MAX(share_rate) share_rate')->group('bet_detail_id')->select()->toArray();$map=[];foreach($allocations as $row)$map[(int)$row['bet_detail_id']]=$row;
        foreach($rows as &$row){$amount=(float)$row['amount'];$win=(float)$row['win_amount'];$rebate=(float)$row['rebate'];$memberProfit=$win+$rebate-$amount;$intercepted=(float)($map[(int)$row['id']]['intercepted']??0);$shareRate=max(0,min(100,(float)($row['member_share_rate']??0)));$occupation=$memberProfit*$shareRate/100;$darkWater=round($amount*$darkRate,2);$brightWater=round($occupation*$brightRate,2);$numbers=preg_split('/[\s,，]+/u',trim((string)$row['number_text']),-1,PREG_SPLIT_NO_EMPTY)?:[];$row['bet_count']=max(1,count($numbers));$row['intercepted']=$intercepted;$row['requested_share']=(float)($map[(int)$row['id']]['requested']??0);$row['share_rate']=$shareRate;$row['offline_water']=$darkWater;$row['bright_water']=$brightWater;$row['house_profit']=-$memberProfit;$row['share_profit']=$occupation;$row['agent_profit']=$occupation+$darkWater+$brightWater;}unset($row);return$rows;
    }

    private function contribution(array $details): array
    {
        $groups=[];foreach($details as $row){$key=(int)$row['user_id'];if(!isset($groups[$key]))$groups[$key]=['member'=>(string)$row['username'],'share_amount'=>0.0,'share_total_amount'=>0.0,'share_total_profit'=>0.0,'offline_water'=>0.0,'percentage_share_profit'=>0.0,'actual_share_profit'=>0.0,'share_percentage'=>0.0,'contribution'=>0.0];$groups[$key]['share_amount']+=(float)$row['intercepted'];$groups[$key]['share_total_amount']+=(float)$row['amount'];$groups[$key]['share_total_profit']-=(float)$row['house_profit'];$groups[$key]['offline_water']+=(float)$row['offline_water'];$groups[$key]['percentage_share_profit']+=(float)$row['share_profit'];$groups[$key]['actual_share_profit']+=(float)$row['share_profit'];}
        $totalActual=array_sum(array_column($groups,'actual_share_profit'));foreach($groups as &$row){$row['share_percentage']=$row['share_total_amount']>0?$row['share_amount']/$row['share_total_amount']*100:0;$row['contribution']=abs($totalActual)>0?abs($row['actual_share_profit'])/abs($totalActual)*100:0;foreach(['share_amount','share_total_amount','share_total_profit','offline_water','percentage_share_profit','actual_share_profit']as$key)$row[$key]=$this->number((float)$row[$key]);foreach(['share_percentage','contribution']as$key)$row[$key]=$this->number((float)$row[$key]).'%';}unset($row);return array_values($groups);
    }

    private function ledgerRows(array $details,string $view,array $session): array
    {
        $groups=[];$path=in_array($view,['daily_path','monthly_path'],true);$monthly=in_array($view,['monthly','monthly_path'],true);$agentName=(string)($session['username']??$session['display_name']??'本级代理');
        foreach($details as $row){$head=$monthly?(string)$row['issue_no']:($path?$agentName:(string)$row['username']);$key=$head.'|'.(string)$row['category'];if(!isset($groups[$key]))$groups[$key]=['label'=>$head,'category'=>(string)($row['category']?:'未分类'),'bet_count'=>0,'amount'=>0.0,'rebate'=>0.0,'offline_water'=>0.0,'win_amount'=>0.0,'profit'=>0.0];$groups[$key]['bet_count']+=(int)$row['bet_count'];$groups[$key]['amount']+=(float)$row['amount'];$groups[$key]['rebate']+=(float)$row['rebate'];$groups[$key]['offline_water']+=(float)$row['offline_water'];$groups[$key]['win_amount']+=(float)$row['win_amount'];$groups[$key]['profit']+=(float)($row['agent_profit']??0);}
        foreach($groups as &$row)foreach(['amount','rebate','offline_water','win_amount','profit']as$key)$row[$key]=$this->number((float)$row[$key]);unset($row);return array_values($groups);
    }
    private function number(float $value):string{return rtrim(rtrim(number_format(abs($value)<0.005?0:$value,2,'.',''),'0'),'.')?:'0';}
    private function limitIssues(array $issues,array $session,int $lotteryId):array { if(empty($session['is_subaccount'])||empty($session['report_limit_enabled']))return$issues;$from=(string)($session['report_from_issue']??'');$to=(string)($session['report_to_issue']??'');if($from===''||$to==='')return$issues;$fromDay=(string)Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('code',$from)->value('draw_day');$toDay=(string)Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('code',$to)->value('draw_day');if($fromDay===''||$toDay==='')return[];if($fromDay>$toDay)[$fromDay,$toDay]=[$toDay,$fromDay];$allowed=array_map('strval',Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('draw_day','>=',$fromDay)->where('draw_day','<=',$toDay)->column('code'));return array_values(array_intersect($issues,$allowed));}
}
