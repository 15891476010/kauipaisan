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

    public function config(Request $request): \think\response\Json
    {
        $session=$this->session($request,'admin');$siteId=$this->scopedSiteId((int)$session['tenant_id'],$request->param('site_id',0));
        return $this->reply(ThirdPartyQuickEntryConfig::publicView(ThirdPartyQuickEntryConfig::load((int)$session['tenant_id'],$siteId)));
    }

    public function saveConfig(Request $request): \think\response\Json
    {
        $session=$this->session($request,'admin');$input=$request->put();$siteId=$this->scopedSiteId((int)$session['tenant_id'],$input['site_id']??$request->param('site_id',0));unset($input['site_id']);
        $config=ThirdPartyQuickEntryConfig::save((int)$session['tenant_id'],$siteId,$input);
        return $this->reply(ThirdPartyQuickEntryConfig::publicView($config),'三方快速录入配置已保存');
    }

    public function test(Request $request): \think\response\Json
    {
        $session=$this->session($request,'admin');$input=$request->post();$siteId=$this->scopedSiteId((int)$session['tenant_id'],$input['site_id']??0);
        $config=ThirdPartyQuickEntryConfig::load((int)$session['tenant_id'],$siteId);
        $text=(string)($input['text']??'123直1元');$lottery=(string)($input['lottery']??'福彩3D');$dlt=$lottery==='排列三'?3:4;
        $result=(new ThirdPartyQuickEntryClient($config))->recognize($text,$dlt);
        return $this->reply(['code'=>ThirdPartyQuickEntryUtils::responseCode($result),'total_amount'=>$result['data']['ta']??null,'total_count'=>$result['data']['tc']??null,'result'=>$result],'三方识别测试完成');
    }

    public function preview(Request $request): \think\response\Json
    {
        $session=$this->session($request,'user');$text=trim((string)$request->post('text',''));$lottery=trim((string)$request->post('lottery','福彩3D'));
        if($text==='')return $this->reply(null,'请输入投注文本',422);
        if(!in_array($lottery,['福彩3D','排列三'],true))return $this->reply(null,'彩种无效',422);
        $config=ThirdPartyQuickEntryConfig::load((int)$session['tenant_id'],(int)$session['site_id']);
        if(!(bool)$config['enabled'])return $this->reply(null,'三方快速录入未启用',409);
        $result=(new ThirdPartyQuickEntryClient($config))->recognize($text,$lottery==='排列三'?3:4);
        $data=(array)($result['data']??[]);
        return $this->reply(['provider'=>'third_party','code'=>ThirdPartyQuickEntryUtils::responseCode($result),'message'=>(string)($result['message']??''),'code_info_list'=>$data['cil']??[],'text_statistics'=>$data['ts']??[],'blur_code_info_list'=>$data['bcil']??[],'result_list'=>$data['rl']??[],'total_amount'=>$data['ta']??0,'total_count'=>$data['tc']??0]);
    }
}
