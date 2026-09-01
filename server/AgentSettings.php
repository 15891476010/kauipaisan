<?php
declare(strict_types=1);
namespace app\controller;
use app\service\OrganizationHierarchy;

use think\Request;
use think\facade\Cache;
use think\facade\Db;

final class AgentSettings
{
    private const FOLLOW_SETTING_KEY = 'agent_follow_platform_interception';

    private function reply(mixed $data=null, string $message='ok', int $code=0): \think\response\Json
    {
        return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]);
    }

    private function session(Request $request): array
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token!==''?Cache::get('token:'.$token):null;
        if (!is_array($session) || ($session['scope']??'')!=='agent') throw new \RuntimeException('未登录或登录已过期');
        if ((int)($session['site_id']??0)<1) throw new \RuntimeException('当前代理未绑定站点');
        return $session;
    }

    private function lottery(array $session, string $name): array
    {
        $query=Db::name('lotteries')->alias('l')->join('site_lotteries sl','sl.lottery_id=l.id')
            ->where('sl.site_id',(int)$session['site_id'])->where('l.status',1)->whereNull('l.deleted_at');
        if ($name!=='') $query->where(function($q) use ($name): void { $q->where('l.name',$name)->whereOr('l.code',$name); });
        $row=$query->field('l.id,l.name,l.code')->order('l.sort asc')->order('l.id asc')->find();
        if (!$row) throw new \InvalidArgumentException('当前彩种不可用');
        return is_object($row)?$row->toArray():$row;
    }

    private function boardCode(Request $request, array $session): string
    {
        $value=strtoupper(trim((string)$request->param('board_code',$request->param('board','A'))));
        if(!preg_match('/^[A-Z][A-Z0-9_]{0,7}$/',$value))$value='A';
        $exists=Db::name('lottery_boards')->where('tenant_id',(int)($session['tenant_id']??1))->where('code',$value)->where('status',1)->value('id');
        if(!$exists) throw new \InvalidArgumentException('当前盘口不可用');
        $settings=Db::name('organization_nodes')->where('id',(int)($session['organization_id']??0))->value('settings');$decoded=is_string($settings)?json_decode($settings,true):$settings;$allowed=is_array($decoded)&&is_array($decoded['board_codes']??null)?$decoded['board_codes']:['A'];if(!in_array($value,array_map('strval',$allowed),true))throw new \InvalidArgumentException('当前层级未分配'.$value.'盘');
        return $value;
    }

    private function settingKey(int $lotteryId,string $boardCode='A'): string { return $boardCode==='A'?'agent_interception_amounts_'.$lotteryId:'agent_interception_amounts_'.$boardCode.'_'.$lotteryId; }

    private function organizationSettings(array $session): array
    {
        $node=OrganizationHierarchy::nodeForSession($session);
        $decoded=$node?json_decode((string)($node['settings']??''),true):null;
        return is_array($decoded)?$decoded:[];
    }

    private function amounts(array $session, int $lotteryId,string $boardCode='A'): array
    {
        $node=OrganizationHierarchy::nodeForSession($session);
        if(!$node||(string)$node['level']!=='agent')return [];
        $settings=$this->organizationSettings($session);$key=$this->settingKey($lotteryId,$boardCode);
        if(isset($settings[$key])&&is_array($settings[$key]))return $settings[$key];
        $raw=Db::name('settings')->where('site_id',(int)$session['site_id'])->where('key',$key)->value('value');
        $decoded=json_decode((string)$raw,true); return is_array($decoded)?$decoded:[];
    }

    private function odds(int $lotteryId, array $amounts,string $boardCode='A'): array
    {
        $rows=Db::name('lottery_odds')->where('lottery_id',$lotteryId)->where('board_code',$boardCode)->where('status',1)->whereNull('deleted_at')->order('sort asc')->order('id asc')->select()->toArray();
        $direct=Db::name('lottery_odds_categories')->where('lottery_id',$lotteryId)->where('board_code',$boardCode)->where('is_playable',1)->where('status',1)->whereNull('deleted_at')->order('sort asc')->order('id asc')->select()->toArray();
        foreach($direct as $row) $rows[]=['id'=>1000000000+(int)$row['id'],'lottery_id'=>$lotteryId,'category_id'=>(int)$row['id'],'category'=>(string)$row['name'],'name'=>(string)$row['name'],'min_bet'=>$row['min_bet'],'odds_limit'=>$row['odds_limit'],'single_bet_limit'=>$row['single_bet_limit'],'single_item_limit'=>$row['single_item_limit'],'sort'=>$row['sort'],'direct_category'=>1];
        usort($rows,static fn(array $a,array $b): int => ((int)$a['sort']<=>(int)$b['sort']) ?: ((int)$a['id']<=>(int)$b['id']));
        foreach($rows as &$row) $row['interception_amount']=$this->number($amounts[(string)(int)$row['id']]??0);
        return $rows;
    }

    private function profile(array $session): array
    {
        $table=(string)($session['account_table']??'agent_admins'); $id=(int)($session['user_id']??0);
        if ($table==='sites') $row=Db::name('sites')->where('id',$id)->field('manager_username AS username,manager_username AS display_name')->find();
        elseif (in_array($table,['agent_admins','site_admins','organization_accounts'],true)) $row=Db::name($table)->where('id',$id)->field('username,display_name')->find();
        else $row=null;
        $node=OrganizationHierarchy::nodeForSession($session);$settings=$this->organizationSettings($session);$siteId=(int)$session['site_id'];$editable=$node&&(string)$node['level']==='agent';
        $follow=$editable?(array_key_exists(self::FOLLOW_SETTING_KEY,$settings)?((int)$settings[self::FOLLOW_SETTING_KEY]===1?1:0):((int)Db::name('settings')->where('site_id',$siteId)->where('key',self::FOLLOW_SETTING_KEY)->value('value')===1?1:0)):0;
        $credit=$node?OrganizationHierarchy::nodeCreditSummary((int)$node['id']):['total_credit'=>'0.00','allocated_credit'=>'0.00','available_credit'=>'0.00'];
        $siteSettings=Db::name('sites')->where('id',$siteId)->value('settings');$siteSettings=is_string($siteSettings)?json_decode($siteSettings,true):(is_array($siteSettings)?$siteSettings:[]);$shareLimit=max(0,min(100,(float)($siteSettings['max_profit_share_rate']??100)));
        return array_merge(['username'=>(string)($row['username']??($session['username']??'')),'display_name'=>(string)($row['display_name']??''),'remark'=>(string)($node['name']??''),'share_limit'=>number_format($shareLimit,4,'.',''),'follow_share'=>$follow,'organization_level'=>(string)($node['level']??''),'interception_editable'=>$editable?1:0,'interception_notice'=>$editable?'':'当前层级不直接承接会员下注，拦货配置仅由直属代理设置并执行。'], $credit);
    }

    public function index(Request $request): \think\response\Json
    {
        $session=$this->session($request); $lottery=$this->lottery($session,trim((string)$request->param('lottery','')));
        $boardCode=$this->boardCode($request,$session);$amounts=$this->amounts($session,(int)$lottery['id'],$boardCode);
        return $this->reply(['profile'=>$this->profile($session),'lottery'=>$lottery,'board_code'=>$boardCode,'odds'=>$this->odds((int)$lottery['id'],$amounts,$boardCode)]);
    }

    public function save(Request $request): \think\response\Json
    {
        $session=$this->session($request); $data=$request->put(); $lottery=$this->lottery($session,trim((string)($data['lottery']??''))); $boardCode=$this->boardCode($request,$session);
        $input=$data['amounts']??null; if(!is_array($input)) throw new \InvalidArgumentException('拦货金额格式不正确');
        $valid=array_map('intval',Db::name('lottery_odds')->where('lottery_id',(int)$lottery['id'])->where('board_code',$boardCode)->where('status',1)->whereNull('deleted_at')->column('id'));
        foreach(Db::name('lottery_odds_categories')->where('lottery_id',(int)$lottery['id'])->where('board_code',$boardCode)->where('is_playable',1)->where('status',1)->whereNull('deleted_at')->column('id') as $id) $valid[]=1000000000+(int)$id;
        $result=[]; foreach($input as $id=>$value) { $oddsId=(int)$id; if(!in_array($oddsId,$valid,true)) continue; if(!is_numeric($value)||(float)$value<0||(float)$value>999999999) throw new \InvalidArgumentException('拦货金额必须是0到999999999之间的数字'); $result[(string)$oddsId]=$this->number($value); }
        $follow=(int)($data['follow_share']??0)===1?1:0;
        $node=OrganizationHierarchy::nodeForSession($session);if(!$node)throw new \RuntimeException('当前账号尚未绑定组织');
        if((string)$node['level']!=='agent')throw new \InvalidArgumentException('当前层级不直接承接会员下注，请在直属代理账号中设置拦货金额');
        $key=$this->settingKey((int)$lottery['id'],$boardCode);$settings=$this->organizationSettings($session);$settings[$key]=$result;$settings[self::FOLLOW_SETTING_KEY]=$follow;
        Db::name('organization_nodes')->where('id',(int)$node['id'])->update(['settings'=>json_encode($settings,JSON_UNESCAPED_UNICODE),'updated_at'=>date('Y-m-d H:i:s')]);
        return $this->reply(['lottery'=>$lottery,'board_code'=>$boardCode,'amounts'=>$result,'follow_share'=>$follow],'设置保存成功');
    }

    private function saveSetting(int $tenantId, int $siteId, string $key, string $value, string $now): void
    {
        $existing=Db::name('settings')->where('site_id',$siteId)->where('key',$key)->find();
        if($existing) Db::name('settings')->where('id',$existing['id'])->update(['value'=>$value,'updated_at'=>$now]);
        else Db::name('settings')->insert(['tenant_id'=>$tenantId,'site_id'=>$siteId,'key'=>$key,'value'=>$value,'updated_at'=>$now]);
    }

    private function number(mixed $value): string { return rtrim(rtrim(number_format((float)$value,2,'.',''),'0'),'.') ?: '0'; }
}
