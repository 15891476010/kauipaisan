<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));
$app->initialize();

use app\service\AccountPresence;

$method=new ReflectionMethod(AccountPresence::class,'addressParts');
$method->setAccessible(true);
$assertions=0;
$check=static function(bool $condition,string $message)use(&$assertions):void{$assertions++;if(!$condition)throw new RuntimeException($message);};
$address=$method->invoke(null,['result'=>['ad_info'=>['nation'=>'中国','province'=>'广东省','city'=>'广州市','district'=>'番禺区','town'=>'某某镇','village'=>'某某村']]]);
$check($address===['中国','广东省','广州市','番禺区','某某镇','某某村'],'嵌套地址未按国家到村级顺序解析');
$ipApi=$method->invoke(null,['country'=>'中国','regionName'=>'北京市','city'=>'北京市','district'=>'朝阳区']);
$check($ipApi===['中国','北京市','朝阳区'],'IP API 地址字段解析错误');
$fallback=$method->invoke(null,['status'=>'fail','message'=>'private range']);
$check($fallback===[],'无地址响应不应伪造地点');
foreach(['admins','site_admins','site_users','agent_admins','agent_subaccounts','organization_accounts','sites','users'] as $table){
    $check(think\facade\Db::query("SHOW COLUMNS FROM `{$table}` LIKE 'last_login_ip'")!==[],"{$table} 缺少 last_login_ip");
    $check(think\facade\Db::query("SHOW COLUMNS FROM `{$table}` LIKE 'last_login_location'")!==[],"{$table} 缺少 last_login_location");
}
echo "Account presence location tests passed: {$assertions} assertions\n";
