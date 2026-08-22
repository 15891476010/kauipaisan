<?php
declare(strict_types=1);
namespace app\controller;

use think\Request;
use think\facade\Cache;
use think\facade\Db;

final class AgentLine
{
    private function reply(mixed $data=null, string $message='ok', int $code=0): \think\response\Json
    {
        return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]);
    }

    private function session(Request $request): array
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token!==''?Cache::get('token:'.$token):null;
        if (!is_array($session) || ($session['scope']??'')!=='agent') throw new \RuntimeException('未登录或登录已过期');
        $siteId=(int)($session['site_id']??0);
        if ($siteId<1) throw new \RuntimeException('当前代理未绑定站点');
        $tenantId=(int)($session['tenant_id']??0);
        if ($tenantId<1) $tenantId=(int)Db::name('sites')->where('id',$siteId)->value('tenant_id');
        if ($tenantId<1) throw new \RuntimeException('当前代理租户信息无效');
        return ['tenant_id'=>$tenantId,'site_id'=>$siteId];
    }

    public function options(Request $request): \think\response\Json
    {
        try {
            $session=$this->session($request);
        } catch (\RuntimeException $error) {
            return json(['code'=>401,'message'=>$error->getMessage(),'data'=>null,'request_id'=>bin2hex(random_bytes(8))],401);
        }
        $rows=Db::name('domains')
            ->where('tenant_id',$session['tenant_id'])
            ->where('site_id',$session['site_id'])
            ->where('domain_type','agent')
            ->where('status',1)
            ->order('is_primary desc')
            ->order('id asc')
            ->field('domain')
            ->select()->toArray();
        $lines=[];
        foreach ($rows as $row) {
            $domain=trim((string)($row['domain']??''));
            if ($domain==='') continue;
            if (!preg_match('/^https?:\/\//i',$domain)) $domain='https://'.$domain;
            $lines[]=['url'=>rtrim($domain,'/'),'line'=>count($lines)+1];
        }
        return $this->reply(['list'=>$lines]);
    }
}
