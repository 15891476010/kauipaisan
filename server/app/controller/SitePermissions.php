<?php
declare(strict_types=1);

namespace app\controller;

use app\service\AgentAuthorization;
use think\Request;
use think\facade\Db;
use think\facade\Cache;

final class SitePermissions
{
    private function reply(mixed $data=null,string $message='ok',int $code=0): \think\response\Json
    {
        return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]);
    }

    private function site(int $siteId): array
    {
        $site=Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find();
        if(!$site)throw new \InvalidArgumentException('站点不存在');
        return $site;
    }

    private function isPlatform(Request $request): bool
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token!==''?Cache::get('token:'.$token):null;
        return is_array($session)&&($session['scope']??'')==='admin'&&($session['admin_role']??'platform')==='platform';
    }

    private function forbidden(): \think\response\Json
    {
        return json(['code'=>403,'message'=>'只有 SaaS 平台管理员可以配置站点路由权限','data'=>null,'request_id'=>bin2hex(random_bytes(8))],403);
    }

    public function show(Request $request,int $siteId): \think\response\Json
    {
        if(!$this->isPlatform($request))return $this->forbidden();
        $site=$this->site($siteId);
        $permissionsByLevel=AgentAuthorization::sitePermissionsByLevel($siteId);
        $allowedCodesByLevel=[];foreach(AgentAuthorization::LEVELS as $level=>$label)$allowedCodesByLevel[$level]=AgentAuthorization::codesForLevel($level);
        $levels=[];foreach(AgentAuthorization::LEVELS as $value=>$label)$levels[]=['value'=>$value,'label'=>$label];
        return $this->reply(['site'=>['id'=>(int)$site['id'],'name'=>(string)$site['name']],'levels'=>$levels,'tree'=>AgentAuthorization::TREE,'allowed_codes_by_level'=>$allowedCodesByLevel,'permissions_by_level'=>$permissionsByLevel]);
    }

    public function save(Request $request,int $siteId): \think\response\Json
    {
        if(!$this->isPlatform($request))return $this->forbidden();
        $site=$this->site($siteId);
        $submitted=$request->post('permissions_by_level',[]);
        $permissionsByLevel=[];
        foreach(AgentAuthorization::LEVELS as $level=>$label)$permissionsByLevel[$level]=AgentAuthorization::normalizeForLevel(is_array($submitted)?($submitted[$level]??[]):[],$level);
        $settings=is_string($site['settings']??null)?json_decode((string)$site['settings'],true):(array)($site['settings']??[]);
        if(!is_array($settings))$settings=[];
        unset($settings['agent_permissions']);
        $settings['agent_permissions_by_level']=$permissionsByLevel;
        Db::name('sites')->where('id',$siteId)->update(['settings'=>json_encode($settings,JSON_UNESCAPED_UNICODE),'updated_at'=>date('Y-m-d H:i:s')]);
        return $this->reply(['permissions_by_level'=>$permissionsByLevel],'站点分层路由权限已保存');
    }
}
