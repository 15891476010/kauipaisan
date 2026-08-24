<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));
$app->initialize();

use app\controller\Auth;
use app\service\AuditLogger;
use app\service\OrganizationHierarchy;
use think\Request;
use think\facade\Cache;
use think\facade\Db;

$site=Db::name('sites')->where('status',1)->whereNull('deleted_at')->find();
if(!$site)throw new RuntimeException('缺少站点测试数据');
$root=OrganizationHierarchy::rootForSite((int)$site['id']);
if(!$root)throw new RuntimeException('站点缺少根总监');
$rootAccount=Db::name('organization_accounts')->where('organization_id',(int)$root['id'])->where('status',1)->whereNull('deleted_at')->find();
$domain=Db::name('domains')->where('site_id',(int)$site['id'])->where('domain_type','agent')->where('status',1)->find();
if(!$rootAccount||!$domain)throw new RuntimeException('站点缺少总监管理员或代理端域名');

$controller=new Auth();
$decode=static fn($response):array=>json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
$check=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$username='site-only-test-'.bin2hex(random_bytes(4));
$password='SiteOnly#2026';
$now=date('Y-m-d H:i:s');
$tokens=[];

Db::startTrans();
try{
    Db::name('site_admins')->insert(['tenant_id'=>(int)$site['tenant_id'],'site_id'=>(int)$site['id'],'username'=>$username,'display_name'=>'站点后台测试账号','password'=>password_hash($password,PASSWORD_DEFAULT),'status'=>1,'created_at'=>$now,'updated_at'=>$now]);
    $captcha='role-separation-'.bin2hex(random_bytes(6));Cache::set('captcha:'.$captcha,['answer'=>'7'],300);
    $agentRequest=(new Request())->withHeader(['x-agent-domain'=>(string)$domain['domain']])->withPost(['username'=>$username,'password'=>$password,'captcha_id'=>$captcha,'captcha'=>'7']);
    $agentResult=$decode($controller->agentLogin($agentRequest));
    $check((int)($agentResult['code']??0)===401&&str_contains((string)($agentResult['message']??''),'总监账号'),'站点管理员仍可登录代理中心');

    $adminResult=$decode($controller->adminLogin((new Request())->withPost(['username'=>$username,'password'=>$password])));
    $check((int)($adminResult['code']??-1)===0&&($adminResult['data']['user']['role']??'')==='site','站点管理员无法登录站点后台');
    if(!empty($adminResult['data']['token']))$tokens[]=(string)$adminResult['data']['token'];

    $platformToken='platform-enter-test-'.bin2hex(random_bytes(6));
    Cache::set('token:'.$platformToken,['scope'=>'admin','admin_role'=>'platform','user_id'=>999,'username'=>'平台测试管理员'],300);
    $tokens[]=$platformToken;
    $enterResult=$decode($controller->adminEnter((new Request())->withHeader(['authorization'=>'Bearer '.$platformToken])->withGet(['id'=>(int)$site['id']])));
    $check((int)($enterResult['code']??-1)===0,'总平台无法临时代入站点');
    $enterToken=(string)($enterResult['data']['token']??'');$tokens[]=$enterToken;
    $session=$enterToken!==''?Cache::get('token:'.$enterToken):null;
    $check(is_array($session)&&($session['account_table']??'')==='organization_accounts','总平台代入仍复用了站点管理员身份');
    $check((int)($session['organization_id']??0)===(int)$root['id']&&($session['impersonation']??0)===1,'总代入未绑定根总监或缺少代入标记');
    $check((int)($session['impersonated_by']??0)===999&&($session['impersonated_by_username']??'')==='平台测试管理员','总代入未记录平台操作人');
    AuditLogger::write($session,'role_separation_test','site',['marker'=>$username],'127.0.0.1');
    $audit=Db::name('audit_logs')->where('action','role_separation_test')->order('id desc')->find();
    $auditPayload=$audit?json_decode((string)$audit['payload'],true):null;
    $check((int)($auditPayload['_impersonation']['platform_user_id']??0)===999,'代入操作审计未保存平台操作人');
    Db::rollback();
}catch(Throwable $error){Db::rollback();throw $error;}
finally{foreach($tokens as $token)if($token!=='')Cache::delete('token:'.$token);}

echo "Auth role separation tests passed\n";
