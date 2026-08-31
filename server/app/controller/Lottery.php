<?php
declare(strict_types=1);
namespace app\controller;

use think\Request;
use think\facade\Cache;
use think\facade\Db;
use app\service\SystemLotteryService;

final class Lottery
{
    private const DEFAULT_BASE_URL='https://api.huiniao.top/interface/home/lotteryHistory';
    private function reply(mixed $data=null, string $message='ok', int $code=0): \think\response\Json { return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]); }
    private function session(Request $request, string $scope): array
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token !== '' ? Cache::get('token:'.$token) : null;
        if (!is_array($session) || ($session['scope'] ?? '') !== $scope) throw new \RuntimeException('未登录或登录已过期');
        return $session;
    }
    private function catalog(int $tenantId=1): \think\db\Query { return Db::name('lotteries')->where('tenant_id',$tenantId)->whereNull('deleted_at'); }
    private function baseUrl(): string { $value=Db::name('settings')->where('tenant_id',1)->whereNull('site_id')->where('key','lottery_api_base_url')->value('value'); return trim((string)$value) ?: self::DEFAULT_BASE_URL; }
    private function apiType(array $lottery): string { $map=['fc3d'=>'fcsd','pl3'=>'pls']; return $map[(string)$lottery['code']] ?? (string)$lottery['code']; }
    public function index(Request $request): \think\response\Json
    {
        $this->session($request,'admin');
        $query=$this->catalog();
        $keyword=trim((string)$request->param('keyword',''));
        if ($keyword !== '') $query->where(function($q) use ($keyword) { $q->whereLike('name','%'.$keyword.'%')->whereOr('code','like','%'.$keyword.'%'); });
        $total=(clone $query)->count();
        $rows=$query->order('sort asc')->order('id asc')->page(max(1,(int)$request->param('page',1)),min(100,max(1,(int)$request->param('page_size',50))))->select()->toArray();
        foreach ($rows as &$row) { $row['site_ids']=Db::name('site_lotteries')->where('lottery_id',$row['id'])->column('site_id'); }
        return $this->reply(['list'=>$rows,'total'=>$total]);
    }
    public function create(Request $request): \think\response\Json
    {
        $this->session($request,'admin'); $data=$request->post(); $name=trim((string)($data['name']??'')); $code=trim((string)($data['code']??''));
        if ($name==='' || $code==='') throw new \InvalidArgumentException('请输入彩票名称和编码');
        if (!preg_match('/^[A-Za-z0-9_-]+$/',$code)) throw new \InvalidArgumentException('编码只能包含字母、数字、下划线和短横线');
        $sourceType=$this->sourceType($data['source_type']??'official'); $interval=$this->systemInterval($data['system_interval_seconds']??60); $issueMode=$this->systemIssueMode($data['system_issue_mode']??'auto'); $initialIssue=$this->initialIssue($data['system_initial_issue']??null,$sourceType,$issueMode);
        $templateId=$this->oddsSourceId($data['odds_source_lottery_id']??null,0);
        $controls=$this->bettingControls($data);$unitStake=$this->unitStake($data['unit_stake']??2);
        $now=date('Y-m-d H:i:s'); $id=(int)Db::name('lotteries')->insertGetId(array_merge(['tenant_id'=>1,'name'=>$name,'code'=>$code,'source_type'=>$sourceType,'system_interval_seconds'=>$interval,'system_issue_mode'=>$issueMode,'system_initial_issue'=>$initialIssue,'odds_source_lottery_id'=>$templateId?:null,'unit_stake'=>number_format($unitStake,2,'.',''),'status'=>(int)($data['status']??1),'sort'=>(int)($data['sort']??0),'created_at'=>$now,'updated_at'=>$now],$controls));
        if ($templateId>0) $this->copyOddsFromLottery($templateId,$id);
        if ($sourceType==='system' && (int)($data['status']??1)===1) (new SystemLotteryService())->runLottery(Db::name('lotteries')->where('id',$id)->find()?:[]);
        return $this->reply(['id'=>$id],'彩票创建成功');
    }
    public function update(Request $request): \think\response\Json
    {
        $this->session($request,'admin'); $id=(int)$request->param('id'); $data=$request->put(); unset($data['id'],$data['tenant_id'],$data['site_ids'],$data['created_at'],$data['deleted_at']);
        $current=$this->catalog()->where('id',$id)->find(); if (!$current) throw new \InvalidArgumentException('彩票不存在');
        if (isset($data['name'])) { $data['name']=trim((string)$data['name']); if ($data['name']==='') throw new \InvalidArgumentException('请输入彩票名称'); }
        if (isset($data['code']) && !preg_match('/^[A-Za-z0-9_-]+$/',(string)$data['code'])) throw new \InvalidArgumentException('编码只能包含字母、数字、下划线和短横线');
        $sourceType=$this->sourceType($data['source_type']??$current['source_type']??'official'); $data['source_type']=$sourceType;
        $templateChanged=false;
        if (array_key_exists('system_interval_seconds',$data)) $data['system_interval_seconds']=$this->systemInterval($data['system_interval_seconds']);
        $issueMode=$this->systemIssueMode($data['system_issue_mode']??$current['system_issue_mode']??'auto'); $data['system_issue_mode']=$issueMode;
        if (array_key_exists('system_initial_issue',$data) || array_key_exists('system_issue_mode',$data)) $data['system_initial_issue']=$this->initialIssue($data['system_initial_issue']??$current['system_initial_issue']??null,$sourceType,$issueMode);
        if (array_key_exists('odds_source_lottery_id',$data)) { $newTemplate=$this->oddsSourceId($data['odds_source_lottery_id'],$id); $templateChanged=$newTemplate!==(int)($current['odds_source_lottery_id']??0); $data['odds_source_lottery_id']=$newTemplate?:null; }
        if(array_key_exists('unit_stake',$data))$data['unit_stake']=number_format($this->unitStake($data['unit_stake']),2,'.','');
        $data=array_merge($data,$this->bettingControls($data,true));
        $data['updated_at']=date('Y-m-d H:i:s'); Db::name('lotteries')->where('id',$id)->whereNull('deleted_at')->update($data);
        if ($templateChanged && (int)($data['odds_source_lottery_id']??0)>0) $this->copyOddsFromLottery((int)$data['odds_source_lottery_id'],$id,true);
        if ($sourceType==='system' && (int)($data['status']??$current['status']??1)===1) (new SystemLotteryService())->runLottery(Db::name('lotteries')->where('id',$id)->find()?:[]);
        return $this->reply(null,'彩票已更新');
    }
    public function delete(Request $request): \think\response\Json
    {
        $this->session($request,'admin'); $id=(int)$request->param('id'); Db::name('lotteries')->where('id',$id)->update(['deleted_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]); return $this->reply(null,'彩票已删除');
    }
    public function rules(Request $request): \think\response\Json
    {
        $this->session($request,'admin');
        $id=(int)$request->param('id');
        $lottery=$this->catalog()->where('id',$id)->field('id,name,code')->find();
        if (!$lottery) throw new \InvalidArgumentException('彩票不存在');
        if (is_object($lottery)) $lottery=$lottery->toArray();
        $content=(string)Db::name('settings')->where('tenant_id',1)->whereNull('site_id')->where('key','lottery_rule_content_'.$id)->value('value');
        return $this->reply(array_merge($lottery,['content'=>$content]));
    }
    public function saveRules(Request $request): \think\response\Json
    {
        $this->session($request,'admin');
        $id=(int)$request->param('id');
        $lottery=$this->catalog()->where('id',$id)->field('id,name,code')->find();
        if (!$lottery) throw new \InvalidArgumentException('彩票不存在');
        if (is_object($lottery)) $lottery=$lottery->toArray();
        $data=$request->put();
        $content=trim((string)($data['content']??''));
        if ($content === '') throw new \InvalidArgumentException('请输入规则内容');
        if (mb_strlen($content)>100000) throw new \InvalidArgumentException('规则内容不能超过100000个字符');
        $key='lottery_rule_content_'.$id; $now=date('Y-m-d H:i:s');
        $existing=Db::name('settings')->where('tenant_id',1)->whereNull('site_id')->where('key',$key)->find();
        if ($existing) Db::name('settings')->where('id',$existing['id'])->update(['value'=>$content,'updated_at'=>$now]);
        else Db::name('settings')->insert(['tenant_id'=>1,'site_id'=>null,'key'=>$key,'value'=>$content,'updated_at'=>$now]);
        return $this->reply(array_merge($lottery,['content'=>$content]),'规则配置已保存');
    }
    public function assign(Request $request): \think\response\Json
    {
        $this->session($request,'admin'); $lotteryId=(int)$request->param('id'); $payload=$request->put();
        if (!array_key_exists('site_ids',$payload) || !is_array($payload['site_ids'])) throw new \InvalidArgumentException('站点分配数据格式不正确');
        $ids=array_values(array_unique(array_filter(array_map('intval',$payload['site_ids']),static fn(int $id): bool => $id > 0)));
        $lottery=$this->catalog()->where('id',$lotteryId)->find(); if (!$lottery) throw new \InvalidArgumentException('彩票不存在');
        $valid=$ids ? Db::name('sites')->whereIn('id',$ids)->whereNull('deleted_at')->column('id') : []; $now=date('Y-m-d H:i:s');
        Db::transaction(function () use ($lotteryId,$valid,$now): void {
            Db::name('site_lotteries')->where('lottery_id',$lotteryId)->delete();
            foreach ($valid as $siteId) Db::name('site_lotteries')->insert(['tenant_id'=>1,'site_id'=>$siteId,'lottery_id'=>$lotteryId,'created_at'=>$now]);
        });
        return $this->reply(['site_ids'=>array_map('intval',$valid)],'彩票分配已保存');
    }
    private function timingState(array $control, ?int $now=null): array
    {
        $now ??= time(); $rules=(array)($control['timing_rules']??[]); $minutes=(int)date('H',$now)*60+(int)date('i',$now); $matched=null;
        foreach($rules as $rule){ if(!is_array($rule)) continue; [$sh,$sm]=array_map('intval',explode(':',(string)($rule['start_time']??'00:00'))); [$eh,$em]=array_map('intval',explode(':',(string)($rule['end_time']??'23:59'))); $start=$sh*60+$sm; $end=$eh*60+$em; $in=$start===$end?true:($start<$end?($minutes>=$start&&$minutes<$end):($minutes>=$start||$minutes<$end)); if($in){$matched=$rule;break;} }
        if($matched!==null) return ['allow_bet'=>(int)($matched['allow_bet']??1)===1,'mask_enabled'=>(int)($matched['mask_enabled']??0)===1,'show_next_issue'=>(int)($matched['show_next_issue']??1)===1,'header_show_next_issue'=>(int)($matched['header_show_next_issue']??($matched['show_next_issue']??1))===1,'display_text'=>(string)($matched['display_text']??'')];
        return ['allow_bet'=>true,'mask_enabled'=>(int)($control['mask_enabled']??1)!==0,'show_next_issue'=>true,'header_show_next_issue'=>true,'display_text'=>''];
    }
    private function siteList(int $siteId, int $tenantId=1, int $userId=0): array
    {
        if ($siteId < 1 || !Db::name('sites')->where('id',$siteId)->where('tenant_id',$tenantId)->where('status',1)->whereNull('deleted_at')->find()) return [];
        $configuredLimit=(int)Db::name('settings')->where('site_id',$siteId)->where('key','draw_history_limit')->value('value');
        $drawHistoryLimit=$configuredLimit>0?min(200,$configuredLimit):80;
        $rows=Db::name('lotteries')->alias('l')
            ->join('site_lotteries sl','sl.lottery_id=l.id')
            ->where('sl.site_id',$siteId)
            ->where('sl.tenant_id',$tenantId)
            ->where('l.tenant_id',$tenantId)
            ->where('l.status',1)
            ->whereNull('l.deleted_at')
            ->field('l.id,l.name,l.code,l.sort,l.unit_stake,l.source_type,l.system_interval_seconds,l.system_issue_mode,l.system_initial_issue,l.odds_source_lottery_id,l.cutoff_enabled,l.cutoff_time,l.mask_enabled,l.refund_enabled')
            ->order('l.sort asc')
            ->order('l.id asc')
            ->select()->toArray();
        $permissionMap=[];
        if ($userId > 0) foreach (Db::name('user_lottery_permissions')->where('site_id',$siteId)->where('user_id',$userId)->field('lottery_id,can_view,can_bet')->select()->toArray() as $permission) $permissionMap[(int)$permission['lottery_id']]=$permission;
        if ($permissionMap) $rows=array_values(array_filter($rows,static fn(array $row): bool => (int)($permissionMap[(int)$row['id']]['can_view']??0)===1));
        foreach ($rows as &$row) {
            $siteControls=json_decode((string)Db::name('settings')->where('site_id',$siteId)->where('key','lottery_betting_controls')->value('value'),true);
            $siteControl=is_array($siteControls)?($siteControls[(string)$row['id']]??[]):[];
            if (is_array($siteControl)) foreach (['cutoff_enabled','cutoff_time','mask_enabled','refund_enabled','timing_rules'] as $field) if (array_key_exists($field,$siteControl)) $row[$field]=$siteControl[$field];
            $timing=$this->timingState(is_array($siteControl)?$siteControl:$row);
            $row['timing_text']=$timing['display_text']; $row['timing_can_bet']=$timing['allow_bet']; $row['timing_mask']=$timing['mask_enabled']; $row['show_next_issue']=$timing['show_next_issue']; $row['header_show_next_issue']=$timing['header_show_next_issue'];
            if ((string)($row['source_type']??'official')==='system') { try { (new SystemLotteryService())->runLottery($row); } catch (\Throwable) {} }
            $latest=Db::name('lottery_histories')->where('lottery_id',(int)$row['id'])->where('is_opened',1)->order('open_time desc')->order('id desc')->field('code,open_time,next_code,next_open_time,numbers')->find();
            $pending=Db::name('lottery_histories')->where('lottery_id',(int)$row['id'])->where('is_opened',0)->order('open_time asc')->order('id asc')->field('code,open_time')->find();
            $row['latest_code']=(string)($latest['code']??'');
            $row['latest_numbers']=(string)($latest['numbers']??'');
            $row['header_next_code']=(string)($latest['next_code']??($pending['code']??''));
            $row['header_next_open_time']=$latest['next_open_time']??($pending['open_time']??null);
            $showNext=(bool)$row['show_next_issue'];
            $row['next_code']=$showNext?(string)($latest['next_code']??($pending['code']??'')):(string)($latest['code']??'');
            $row['next_open_time']=$showNext?($latest['next_open_time']??($pending['open_time']??null)):($latest['open_time']??null);
            // The issue dropdown and recent-draw panel use the same site-level
            // limit configured in 下注控制. Never load the complete history.
            $recentQuery=Db::name('lottery_histories')->where('lottery_id',(int)$row['id']);
            if (!$showNext) $recentQuery->where('is_opened',1);
            $recent=$recentQuery->order('open_time desc')->order('id desc')->limit($drawHistoryLimit)->field('code,draw_day,numbers,is_opened')->select()->toArray();
            $row['recent_issues']=$recent;
            $row['recent_issues']=array_map(static fn(array $issue): array => ['code'=>(string)$issue['code'],'draw_day'=>$issue['draw_day']??null],$row['recent_issues']);
            $row['can_bet']=$permissionMap ? (int)($permissionMap[(int)$row['id']]['can_bet']??0)===1 : true;
        }
        unset($row);
        return $rows;
    }
    private function bettingControls(array $data, bool $partial=false): array
    {
        $result=[];
        foreach (['cutoff_enabled','mask_enabled','refund_enabled'] as $field) {
            if ($partial && !array_key_exists($field,$data)) continue;
            $default=$field==='mask_enabled'||$field==='refund_enabled' ? 1 : 0;
            $result[$field]=(int)($data[$field]??$default)===1 ? 1 : 0;
        }
        if (!$partial || array_key_exists('cutoff_time',$data)) {
            $time=trim((string)($data['cutoff_time']??''));
            if ($time!=='' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$time)) throw new \InvalidArgumentException('截止时间必须是 HH:mm 格式');
            $result['cutoff_time']=$time===''?null:$time;
        }
        return $result;
    }
    private function unitStake(mixed $value): float
    {
        if(!is_numeric($value)||(float)$value<=0||(float)$value>1000000)throw new \InvalidArgumentException('单注彩票金额必须大于0且不能超过1000000元');
        return round((float)$value,2);
    }
    public function user(Request $request): \think\response\Json
    {
        $session=$this->session($request,'user'); $siteId=(int)($session['site_id']??0); $userId=($session['user_type']??'site-user')==='site-user'?(int)($session['user_id']??0):0; return $this->reply(['list'=>$this->siteList($siteId,(int)($session['tenant_id']??1),$userId)]);
    }
    public function agent(Request $request): \think\response\Json
    {
        $session=$this->session($request,'agent');
        $siteId=(int)($session['site_id']??0);
        if ($siteId < 1) throw new \RuntimeException('当前代理未绑定站点，无法获取彩票');
        $list=$this->siteList($siteId,(int)($session['tenant_id']??1));
        if (!empty($session['is_subaccount'])) { $allowed=array_map('intval',(array)($session['lottery_permissions']??[])); $list=array_values(array_filter($list,static fn(array $row): bool=>in_array((int)$row['id'],$allowed,true))); }
        return $this->reply(['list'=>$list,'site_id'=>$siteId]);
    }
    public function config(Request $request): \think\response\Json
    {
        $this->session($request,'admin'); return $this->reply(['base_url'=>$this->baseUrl()]);
    }
    public function saveConfig(Request $request): \think\response\Json
    {
        $this->session($request,'admin'); $url=trim((string)$request->put('base_url','')); if (!filter_var($url,FILTER_VALIDATE_URL)) throw new \InvalidArgumentException('请输入有效的开奖接口 Base URL');
        $now=date('Y-m-d H:i:s'); $existing=Db::name('settings')->where('tenant_id',1)->whereNull('site_id')->where('key','lottery_api_base_url')->find();
        if ($existing) Db::name('settings')->where('id',$existing['id'])->update(['value'=>$url,'updated_at'=>$now]); else Db::name('settings')->insert(['tenant_id'=>1,'site_id'=>null,'key'=>'lottery_api_base_url','value'=>$url,'updated_at'=>$now]);
        return $this->reply(['base_url'=>$url],'开奖接口地址已保存');
    }
    public function testConfig(Request $request): \think\response\Json
    {
        $this->session($request,'admin');
        $base=$this->baseUrl(); $url=$base.'?'.http_build_query(['type'=>'pls','page'=>1,'limit'=>1]);
        $started=microtime(true); $context=stream_context_create(['http'=>['timeout'=>10,'ignore_errors'=>true,'header'=>"Accept: application/json\r\n"]]); $raw=@file_get_contents($url,false,$context); $elapsed=round((microtime(true)-$started)*1000,2);
        $status=0; foreach (($http_response_header ?? []) as $header) if (preg_match('/^HTTP\/\S+\s+(\d+)/i',$header,$match)) { $status=(int)$match[1]; break; }
        if ($raw===false) return $this->reply(['base_url'=>$base,'url'=>$url,'http_status'=>$status,'response_time_ms'=>$elapsed,'available'=>false],'开奖接口连接失败',502);
        $payload=json_decode($raw,true); $success=is_array($payload) && (int)($payload['code']??0)===1;
        return $this->reply(['base_url'=>$base,'url'=>$url,'http_status'=>$status,'response_time_ms'=>$elapsed,'available'=>$success,'api_code'=>$payload['code']??null],'测试完成');
    }
    public function history(Request $request): \think\response\Json
    {
        $this->session($request,'admin'); $lotteryId=(int)$request->param('id'); $page=max(1,(int)$request->param('page',1)); $size=min(100,max(1,(int)$request->param('page_size',20))); $query=Db::name('lottery_histories')->where('lottery_id',$lotteryId); $total=(clone $query)->count(); return $this->reply(['list'=>$query->order('draw_day desc')->order('code desc')->page($page,$size)->select()->toArray(),'total'=>$total,'page'=>$page,'page_size'=>$size]);
    }
    public function updateHistory(Request $request, int $id): \think\response\Json
    {
        $this->session($request,'admin'); $history=Db::name('lottery_histories')->where('id',$id)->find();
        if (!$history) throw new \InvalidArgumentException('开奖记录不存在');
        $lottery=$this->catalog()->where('id',(int)$history['lottery_id'])->find();
        if (!$lottery || (string)($lottery['source_type']??'official')!=='system') throw new \RuntimeException('只有系统彩可以修改预生成开奖号码');
        if ((int)($history['is_opened']??1)===1) throw new \RuntimeException('该期已经开奖，不能修改历史号码');
        $data=$request->put(); $digits='';
        if (array_key_exists('numbers',$data)) $digits=preg_replace('/\D/','',(string)$data['numbers'])??'';
        else $digits=implode('',array_map(static fn($value): string => (string)(int)$value,[(int)($data['one']??-1),(int)($data['two']??-1),(int)($data['three']??-1)]));
        if (!preg_match('/^\d{3}$/',$digits)) throw new \InvalidArgumentException('开奖号码必须是三位数字');
        $parts=str_split($digits); $update=['one'=>(int)$parts[0],'two'=>(int)$parts[1],'three'=>(int)$parts[2],'numbers'=>implode(' ',$parts),'updated_at'=>date('Y-m-d H:i:s')];
        Db::name('lottery_histories')->where('id',$id)->update($update);
        return $this->reply(['id'=>$id,'numbers'=>implode(' ',$parts)],'开奖号码已保存');
    }
    public function copyOdds(Request $request, int $id): \think\response\Json
    {
        $this->session($request,'admin'); $data=$request->post(); $sourceId=(int)($data['source_lottery_id']??0); $replace=(int)($data['replace']??0)===1;
        if ($sourceId<1 || $sourceId===$id) throw new \InvalidArgumentException('请选择其他彩种作为赔率来源');
        if (!$this->catalog()->where('id',$sourceId)->find() || !$this->catalog()->where('id',$id)->find()) throw new \InvalidArgumentException('彩票不存在');
        $this->copyOddsFromLottery($sourceId,$id,$replace); Db::name('lotteries')->where('id',$id)->update(['odds_source_lottery_id'=>$sourceId,'updated_at'=>date('Y-m-d H:i:s')]);
        return $this->reply(null,'赔率已复制');
    }
    public function odds(Request $request): \think\response\Json
    {
        $this->session($request,'admin'); $lotteryId=(int)$request->param('id');
        $page=max(1,(int)$request->param('page',1));
        $pageSize=min(100,max(1,(int)$request->param('page_size',10)));
        $categoryQuery=Db::name('lottery_odds_categories')->where('lottery_id',$lotteryId)->whereNull('deleted_at');
        $categoryTotal=(clone $categoryQuery)->count();
        $categories=$categoryQuery->order('sort asc')->order('id asc')->page($page,$pageSize)->select()->toArray();
        foreach ($categories as &$category) {
            $category['children']=(int)$category['is_playable']===1 ? [] : Db::name('lottery_odds')->where('lottery_id',$lotteryId)->where('category_id',(int)$category['id'])->whereNull('deleted_at')->order('sort asc')->order('id asc')->select()->toArray();
        }
        unset($category);
        $total=(int)Db::name('lottery_odds')->where('lottery_id',$lotteryId)->whereNull('deleted_at')->count()
            +(int)Db::name('lottery_odds_categories')->where('lottery_id',$lotteryId)->where('is_playable',1)->whereNull('deleted_at')->count();
        return $this->reply(['categories'=>$categories,'total'=>$total,'category_total'=>$categoryTotal,'page'=>$page,'page_size'=>$pageSize]);
    }
    public function createOddsCategory(Request $request): \think\response\Json
    {
        $this->session($request,'admin'); $lotteryId=(int)$request->param('id'); $data=$request->post(); $name=trim((string)($data['name']??''));
        if ($name==='') throw new \InvalidArgumentException('请输入类别名称');
        $lottery=$this->catalog()->where('id',$lotteryId)->find(); if(!$lottery) throw new \InvalidArgumentException('彩票不存在');
        $now=date('Y-m-d H:i:s'); $row=['tenant_id'=>(int)$lottery['tenant_id'],'lottery_id'=>$lotteryId,'name'=>$name,'is_playable'=>(int)($data['is_playable']??0)===1?1:0,'status'=>(int)($data['status']??1),'sort'=>(int)($data['sort']??0),'created_at'=>$now,'updated_at'=>$now]; if($row['is_playable']) $row=array_merge($row,$this->nullableOddsNumbers($data)); $id=(int)Db::name('lottery_odds_categories')->insertGetId($row);
        return $this->reply(['id'=>$id],'类别已创建');
    }
    public function updateOddsCategory(Request $request): \think\response\Json
    {
        $this->session($request,'admin'); $lotteryId=(int)$request->param('id'); $categoryId=(int)$request->param('category_id'); $data=$request->put();
        $category=Db::name('lottery_odds_categories')->where('id',$categoryId)->where('lottery_id',$lotteryId)->whereNull('deleted_at')->find(); if(!$category) throw new \InvalidArgumentException('赔率类别不存在');
        $update=['updated_at'=>date('Y-m-d H:i:s')];
        if(array_key_exists('name',$data)){ $name=trim((string)$data['name']); if($name==='') throw new \InvalidArgumentException('请输入类别名称'); $update['name']=$name; }
        if(array_key_exists('sort',$data)) $update['sort']=(int)$data['sort']; if(array_key_exists('status',$data)) $update['status']=(int)$data['status']===0?0:1; if(array_key_exists('is_playable',$data)) $update['is_playable']=(int)$data['is_playable']===1?1:0; if((int)($data['is_playable']??$category['is_playable'])===1) $update=array_merge($update,$this->nullableOddsNumbers($data,true));
        Db::transaction(function() use($categoryId,$lotteryId,$update): void { Db::name('lottery_odds_categories')->where('id',$categoryId)->update($update); if(isset($update['name'])) Db::name('lottery_odds')->where('lottery_id',$lotteryId)->where('category_id',$categoryId)->update(['category'=>$update['name'],'updated_at'=>$update['updated_at']]); });
        return $this->reply(null,'类别已更新');
    }
    public function deleteOddsCategory(Request $request): \think\response\Json
    {
        $this->session($request,'admin'); $lotteryId=(int)$request->param('id'); $categoryId=(int)$request->param('category_id'); $now=date('Y-m-d H:i:s');
        Db::transaction(function() use($lotteryId,$categoryId,$now): void { Db::name('lottery_odds_categories')->where('id',$categoryId)->where('lottery_id',$lotteryId)->whereNull('deleted_at')->update(['deleted_at'=>$now,'updated_at'=>$now]); Db::name('lottery_odds')->where('lottery_id',$lotteryId)->where('category_id',$categoryId)->whereNull('deleted_at')->update(['deleted_at'=>$now,'updated_at'=>$now]); });
        return $this->reply(null,'类别及其玩法已删除');
    }
    public function createOdds(Request $request): \think\response\Json
    {
        $this->session($request,'admin'); $lotteryId=(int)$request->param('id'); $data=$request->post(); $categoryId=(int)($data['category_id']??0); $name=trim((string)($data['name']??''));
        $category=Db::name('lottery_odds_categories')->where('id',$categoryId)->where('lottery_id',$lotteryId)->whereNull('deleted_at')->find(); if(!$category) throw new \InvalidArgumentException('请选择赔率类别');
        if($name==='') throw new \InvalidArgumentException('请输入玩法名称');
        $numeric=$this->nullableOddsNumbers($data); $now=date('Y-m-d H:i:s'); $id=Db::name('lottery_odds')->insertGetId(array_merge(['tenant_id'=>(int)$category['tenant_id'],'lottery_id'=>$lotteryId,'category_id'=>$categoryId,'category'=>(string)$category['name'],'name'=>$name,'status'=>(int)($data['status']??1),'sort'=>(int)($data['sort']??0),'created_at'=>$now,'updated_at'=>$now],$numeric)); return $this->reply(['id'=>$id],'玩法赔率已创建');
    }
    public function updateOdds(Request $request): \think\response\Json
    {
        $this->session($request,'admin'); $lotteryId=(int)$request->param('id'); $id=(int)$request->param('odds_id'); $data=$request->put(); $update=$this->nullableOddsNumbers($data,true);
        if(array_key_exists('name',$data)){ $name=trim((string)$data['name']); if($name==='') throw new \InvalidArgumentException('请输入玩法名称'); $update['name']=$name; }
        if(array_key_exists('category_id',$data)){ $category=Db::name('lottery_odds_categories')->where('id',(int)$data['category_id'])->where('lottery_id',$lotteryId)->whereNull('deleted_at')->find(); if(!$category) throw new \InvalidArgumentException('请选择赔率类别'); $update['category_id']=(int)$category['id']; $update['category']=(string)$category['name']; }
        if(array_key_exists('sort',$data)) $update['sort']=(int)$data['sort']; if(array_key_exists('status',$data)) $update['status']=(int)$data['status']===0?0:1; $update['updated_at']=date('Y-m-d H:i:s'); Db::name('lottery_odds')->where('id',$id)->where('lottery_id',$lotteryId)->whereNull('deleted_at')->update($update); return $this->reply(null,'玩法赔率已更新');
    }
    public function deleteOdds(Request $request): \think\response\Json
    {
        $this->session($request,'admin'); $id=(int)$request->param('odds_id'); Db::name('lottery_odds')->where('id',$id)->update(['deleted_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]); return $this->reply(null,'赔率已删除');
    }
    private function nullableOddsNumbers(array $data, bool $partial=false): array
    {
        $result=[];
        foreach(['min_bet','odds_limit','single_bet_limit','single_item_limit','odds'] as $field){
            if($partial&&!array_key_exists($field,$data)) continue;
            $value=$data[$field]??null;
            if($value===null||$value===''){ $result[$field]=null; continue; }
            if(!is_numeric($value)||(float)$value<0) throw new \InvalidArgumentException('赔率和限额必须为非负数字或留空');
            $result[$field]=number_format((float)$value,in_array($field,['single_bet_limit','single_item_limit'],true)?2:4,'.','');
        }
        $result['offline_rebate']='0.0000';
        return $result;
    }
    private function sourceType(mixed $value): string
    {
        $type=strtolower(trim((string)$value)); if (!in_array($type,['official','system'],true)) throw new \InvalidArgumentException('彩种来源只能选择官方彩或系统彩'); return $type;
    }
    private function systemInterval(mixed $value): int
    {
        if (!is_numeric($value) || (int)$value<5 || (int)$value>86400) throw new \InvalidArgumentException('系统彩开奖间隔必须在5到86400秒之间'); return (int)$value;
    }
    private function systemIssueMode(mixed $value): string
    {
        $mode=strtolower(trim((string)$value)); if (!in_array($mode,['auto','manual'],true)) throw new \InvalidArgumentException('系统彩起始期号方式只能选择自动生成或手动填写'); return $mode;
    }
    private function initialIssue(mixed $value,string $sourceType,string $issueMode='auto'): ?string
    {
        $issue=trim((string)$value); if ($sourceType==='system' && $issueMode==='manual' && $issue==='') throw new \InvalidArgumentException('手动模式必须填写系统彩起始期号'); if ($sourceType==='system' && $issue!=='' && !preg_match('/^[A-Za-z0-9_-]+$/',$issue)) throw new \InvalidArgumentException('系统彩起始期号格式不正确'); return $issue===''?null:$issue;
    }
    private function oddsSourceId(mixed $value,int $targetId): int
    {
        $id=(int)($value??0); if ($id<1) return 0; if ($id===$targetId || !$this->catalog()->where('id',$id)->find()) throw new \InvalidArgumentException('赔率来源彩种不存在或不能选择自身'); return $id;
    }
    private function copyOddsFromLottery(int $sourceId,int $targetId,bool $replace=false): void
    {
        $existing=(int)Db::name('lottery_odds_categories')->where('lottery_id',$targetId)->whereNull('deleted_at')->count();
        if ($existing>0 && !$replace) throw new \InvalidArgumentException('当前彩种已有赔率，请先确认替换或清空后再复制');
        $sourceCategories=Db::name('lottery_odds_categories')->where('lottery_id',$sourceId)->whereNull('deleted_at')->order('sort asc')->order('id asc')->select()->toArray();
        if ($sourceCategories===[]) throw new \InvalidArgumentException('来源彩种暂无可复制的赔率');
        Db::transaction(function() use($sourceId,$targetId,$sourceCategories,$replace): void {
            if ($replace) {
                // The category unique key includes soft-deleted rows, so a
                // replacement must remove the old tree before inserting the
                // same category names again. User-specific overrides belong to
                // the old odds IDs and must be reset at the same time.
                Db::name('user_lottery_odds')->where('lottery_id',$targetId)->delete();
                Db::name('lottery_odds')->where('lottery_id',$targetId)->delete();
                Db::name('lottery_odds_categories')->where('lottery_id',$targetId)->delete();
            }
            $now=date('Y-m-d H:i:s');
            foreach ($sourceCategories as $sourceCategory) {
                $categoryData=['tenant_id'=>(int)$sourceCategory['tenant_id'],'lottery_id'=>$targetId,'name'=>(string)$sourceCategory['name'],'is_playable'=>(int)$sourceCategory['is_playable'],'min_bet'=>$sourceCategory['min_bet'],'odds_limit'=>$sourceCategory['odds_limit'],'single_bet_limit'=>$sourceCategory['single_bet_limit'],'single_item_limit'=>$sourceCategory['single_item_limit'],'odds'=>$sourceCategory['odds'],'offline_rebate'=>'0.0000','status'=>(int)$sourceCategory['status'],'sort'=>(int)$sourceCategory['sort'],'created_at'=>$now,'updated_at'=>$now];
                $newCategoryId=(int)Db::name('lottery_odds_categories')->insertGetId($categoryData);
                $plays=Db::name('lottery_odds')->where('lottery_id',$sourceId)->where('category_id',(int)$sourceCategory['id'])->whereNull('deleted_at')->select()->toArray();
                foreach ($plays as $play) Db::name('lottery_odds')->insert(['tenant_id'=>(int)$play['tenant_id'],'lottery_id'=>$targetId,'category_id'=>$newCategoryId,'category'=>(string)$sourceCategory['name'],'name'=>(string)$play['name'],'min_bet'=>$play['min_bet'],'odds_limit'=>$play['odds_limit'],'single_bet_limit'=>$play['single_bet_limit'],'single_item_limit'=>$play['single_item_limit'],'odds'=>$play['odds'],'offline_rebate'=>'0.0000','status'=>(int)$play['status'],'sort'=>(int)$play['sort'],'created_at'=>$now,'updated_at'=>$now]);
            }
        });
    }
}
