<?php
declare(strict_types=1);

namespace app\controller;

use app\service\ThirdPartyQuickEntryClient;
use app\service\ThirdPartyQuickEntryConfig;
use app\service\ThirdPartyQuickEntryUtils;
use think\Request;
use think\facade\Cache;
use think\facade\Db;

/** Admin configuration and user-facing diagnostic endpoint for the adapter. */
final class ThirdPartyQuickEntry
{
    private function reply(mixed $data=null, string $message='ok', int $code=0): \think\response\Json
    {
        return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]);
    }

    private function session(Request $request, string $scope): array
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token!==''?Cache::get('token:'.$token):null;
        if(!is_array($session)||($session['scope']??'')!==$scope)throw new \RuntimeException('未登录或登录已过期');
        $tenant=(int)($session['tenant_id']??0);$site=(int)($session['site_id']??0);$user=(int)($session['user_id']??0);
        if($tenant<1)$tenant=(int)Db::name('sites')->where('id',$site)->value('tenant_id');
        if($tenant<1)throw new \RuntimeException('会话租户无效');
        return ['tenant_id'=>$tenant,'site_id'=>$site,'user_id'=>$user];
    }

    private function scopedSiteId(int $tenantId, mixed $value): ?int
    {
        $siteId=(int)$value;
        if($siteId<1)return null;
        if(!Db::name('sites')->where('tenant_id',$tenantId)->where('id',$siteId)->whereNull('deleted_at')->count())throw new \InvalidArgumentException('站点不存在或不属于当前租户');
        return $siteId;
    }

    private function configView(array $config): array
    {
        $view=ThirdPartyQuickEntryConfig::publicView($config);
        if(trim((string)($config['base_url']??''))===''){$view['current_account']=null;return $view;}
        $runtime=(new ThirdPartyQuickEntryClient($config))->poolStatus();$byId=[];
        foreach((array)$runtime['accounts'] as $row)$byId[(string)($row['id']??'')]=$row;
        foreach($view['accounts'] as &$account){$status=$byId[(string)$account['id']]??[];$account=array_merge($account,$status);}unset($account);
        $view['current_account']=$runtime['current_account'];
        return $view;
    }

    public function config(Request $request): \think\response\Json
    {
        $session=$this->session($request,'admin');$siteId=$this->scopedSiteId((int)$session['tenant_id'],$request->param('site_id',0));
        return $this->reply($this->configView(ThirdPartyQuickEntryConfig::load((int)$session['tenant_id'],$siteId)));
    }

    public function saveConfig(Request $request): \think\response\Json
    {
        $session=$this->session($request,'admin');$input=$request->put();$siteId=$this->scopedSiteId((int)$session['tenant_id'],$input['site_id']??$request->param('site_id',0));unset($input['site_id'],$input['current_account']);
        $config=ThirdPartyQuickEntryConfig::save((int)$session['tenant_id'],$siteId,$input);
        return $this->reply($this->configView($config),'三方快速录入配置已保存');
    }

    public function test(Request $request): \think\response\Json
    {
        $session=$this->session($request,'admin');$input=$request->post();$siteId=$this->scopedSiteId((int)$session['tenant_id'],$input['site_id']??0);
        $config=ThirdPartyQuickEntryConfig::load((int)$session['tenant_id'],$siteId);
        $text=(string)($input['text']??'123直1元');$lottery=(string)($input['lottery']??'福彩3D');$dlt=$lottery==='排列三'?3:4;
        $result=(new ThirdPartyQuickEntryClient($config))->recognize($text,$dlt);
        return $this->reply(['code'=>ThirdPartyQuickEntryUtils::responseCode($result),'total_amount'=>$result['data']['ta']??null,'total_count'=>$result['data']['tc']??null,'account'=>$result['_account']??null,'result'=>$result],'三方识别测试完成');
    }

    public function loginAccount(Request $request, string $accountId=''): \think\response\Json
    {
        $session=$this->session($request,'admin');$input=$request->post();$siteId=$this->scopedSiteId((int)$session['tenant_id'],$input['site_id']??$request->param('site_id',0));
        if($accountId==='')$accountId=trim((string)($input['account_id']??''));
        if($accountId==='')return $this->reply(null,'请选择需要登录的账号',422);
        $config=ThirdPartyQuickEntryConfig::load((int)$session['tenant_id'],$siteId);
        $account=(new ThirdPartyQuickEntryClient($config))->loginAccount($accountId);
        return $this->reply($account,'账号登录成功，AK 已更新');
    }

    public function preview(Request $request): \think\response\Json
    {
        $session=$this->session($request,'user');$text=trim((string)$request->post('text',''));$lottery=trim((string)$request->post('lottery','福彩3D'));
        if($text==='')return $this->reply(null,'请输入投注文本',422);
        if(!in_array($lottery,['福彩3D','排列三'],true))return $this->reply(null,'彩种无效',422);
        $config=ThirdPartyQuickEntryConfig::load((int)$session['tenant_id'],(int)$session['site_id']);
        if(!(bool)$config['enabled'])return $this->reply(null,'三方快速录入未启用',409);
        try{$result=(new ThirdPartyQuickEntryClient($config))->recognize($text,$lottery==='排列三'?3:4);}
        catch(\Throwable $error){$reason='识别服务暂时不可用，请点击“生成”重试';return $this->reply(['provider'=>'third_party','code'=>-1,'message'=>$reason,'result_list'=>[],'total_amount'=>0,'total_count'=>0],$reason,503);}
        $data=(array)($result['data']??[]);
        return $this->reply(['provider'=>'third_party','code'=>ThirdPartyQuickEntryUtils::responseCode($result),'message'=>(string)($result['message']??''),'code_info_list'=>$data['cil']??[],'text_statistics'=>$data['ts']??[],'blur_code_info_list'=>$data['bcil']??[],'result_list'=>$data['rl']??[],'total_amount'=>$data['ta']??0,'total_count'=>$data['tc']??0]);
    }
}
