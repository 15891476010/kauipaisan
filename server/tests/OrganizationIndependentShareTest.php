<?php
declare(strict_types=1);

use app\controller\Organization;
use app\service\OrganizationHierarchy;
use think\Request;
use think\facade\Cache;
use think\facade\Db;

$server=getenv('KPS_SERVER_ROOT') ?: dirname(__DIR__);
require $server.'/vendor/autoload.php';
require $argv[1] ?? $server.'/app/controller/Organization.php';
$app=new think\App($server);
$app->initialize();
$siteId=(int)(getenv('KPS_TEST_SITE_ID') ?: 15);
$site=Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find();
if(!$site)throw new RuntimeException('Test site missing');
$controller=new Organization();
$prefix='lines_'.bin2hex(random_bytes(5));
$token=bin2hex(random_bytes(24));
$now=date('Y-m-d H:i:s');
$sequence=0;
$failures=[];
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{
    $checks++;
    if(!$ok)throw new RuntimeException($message);
};
$request=static function(array $data)use($token):Request{
    return (new Request())->withHeader(['authorization'=>'Bearer '.$token])->withPost($data)->setMethod('POST');
};
$node=static function(int $parent,string $level)use(&$sequence,$prefix,$now,$site):int{
    $name=$prefix.'_'.(++$sequence);
    $id=(int)Db::name('organization_nodes')->insertGetId([
        'tenant_id'=>(int)$site['tenant_id'],'site_id'=>(int)$site['id'],
        'parent_id'=>$parent,'level'=>$level,'name'=>$name,'code'=>$name,
        'path'=>'/','depth'=>1,'credit_limit'=>0,'balance'=>0,
        'permissions'=>'["*"]','settings'=>'{"board_codes":["A"]}',
        'status'=>1,'created_at'=>$now,'updated_at'=>$now,
    ]);
    OrganizationHierarchy::rebuildPath($id);
    return $id;
};
$seed=static function(int $parent,int $child,float $rate)use($site,$now):void{
    Db::name('organization_profit_shares')->insert([
        'tenant_id'=>(int)$site['tenant_id'],'site_id'=>(int)$site['id'],
        'parent_organization_id'=>$parent,'child_organization_id'=>$child,
        'share_rate'=>$rate,'max_share_rate'=>100,'status'=>1,
        'created_at'=>$now,'updated_at'=>$now,
    ]);
};
$rate=static fn(int $id):float=>(float)Db::name('organization_profit_shares')->where('child_organization_id',$id)->value('share_rate');
$authenticate=static function(int $root)use($site,$token):void{
    Cache::set('token:'.$token,[
        'scope'=>'agent','site_id'=>(int)$site['id'],'tenant_id'=>(int)$site['tenant_id'],
        'organization_id'=>$root,'user_id'=>0,'username'=>'independent-line-test',
    ],300);
};
$run=static function(string $label,callable $case)use(&$failures,$token,$prefix,$check):void{
    Db::startTrans();
    try{
        $case();
        echo 'PASS '.$label.PHP_EOL;
    }catch(Throwable $e){
        $failures[]=$label;
        echo 'FAIL '.$label.': '.$e->getMessage().PHP_EOL;
    }finally{
        Db::rollback();
        Cache::delete('token:'.$token);
    }
    foreach(['organization_nodes'=>'code','organization_accounts'=>'username'] as $table=>$column){
        $check(Db::name($table)->whereLike($column,$prefix.'%')->count()===0,'Fixture rollback failed: '.$table);
    }
};
$before=hash('sha256',json_encode(Db::name('organization_profit_shares')->where('site_id',$siteId)->order('id')->select()->toArray()));
foreach(['shareholder','small_shareholder','general_agent','agent'] as $level){
    $run('create/update independent '.$level.' siblings',function()use($level,$node,$seed,$rate,$authenticate,$controller,$request,$check,$prefix){
        $root=$node(0,'director');
        $seed(0,$root,35);
        $authenticate($root);
        $parent=$root;
        foreach(['shareholder','small_shareholder','general_agent'] as $ancestor){
            if($ancestor===$level)break;
            $parent=$node($parent,$ancestor);
        }
        $create=static function(string $suffix,float $percent)use($level,$parent,$controller,$request,$prefix):int{
            $name=$prefix.'_'.$level.'_'.$suffix;
            $response=$controller->agentCreateNode($request([
                'parent_id'=>$parent,'level'=>$level,'name'=>$name,'username'=>$name,
                'display_name'=>$name,'credit_limit'=>0,'share_rate'=>$percent,
                'max_share_rate'=>100,'status'=>1,
            ]))->getData();
            return (int)$response['data']['node_id'];
        };
        $first=$create('a',60);
        $second=$create('b',60);
        $check($rate($first)===60.0&&$rate($second)===60.0,'Both siblings must retain 60%');
        $otherBefore=Db::name('organization_profit_shares')->where('child_organization_id',$second)->find();
        $controller->agentUpdateNode($request(['share_rate'=>75,'max_share_rate'=>100]),$first);
        $check($rate($first)===75.0,'First sibling must save 75%');
        $check($otherBefore===Db::name('organization_profit_shares')->where('child_organization_id',$second)->find(),'Second sibling changed');
        $third=$create('c',100);
        $check($rate($third)===100.0,'A new independent sibling can use 100%');
        $check($rate($root)===35.0,'Director share changed');
        $check(Db::name('organization_accounts')->whereIn('organization_id',[$first,$second,$third])->count()===3,'Created accounts missing');
    });
}
$run('independent root directors',function()use($node,$controller,$request,$siteId,$rate,$check){
    $first=$node(0,'director');$second=$node(0,'director');
    foreach([$first,$second] as $id)$controller->adminSaveProfitShare($request(['share_rate'=>60,'max_share_rate'=>100]),$siteId,$id);
    $check($rate($first)===60.0&&$rate($second)===60.0,'Root directors share an aggregate cap');
});
$run('director settings preserve own share and sibling rates',function()use($node,$seed,$controller,$request,$rate,$check){
    $root=$node(0,'director');$first=$node($root,'shareholder');$second=$node($root,'shareholder');
    $seed(0,$root,35);$seed($root,$first,60);$seed($root,$second,60);
    $controller->adminSetDirectorCreditShare($request(['credit_limit'=>0,'max_share_rate'=>90]),$root);
    $check($rate($root)===35.0,'Director share must stay 35%');
    $check($rate($first)===60.0&&$rate($second)===60.0,'Actual sibling rates changed with cap');
    foreach([$first,$second] as $id)$check((float)Db::name('organization_profit_shares')->where('child_organization_id',$id)->value('max_share_rate')===90.0,'Child cap not updated');
});
$run('per-node site limits and parent/site validation',function()use($node,$site,$controller,$request,$check){
    $root=$node(0,'director');$child=$node($root,'shareholder');
    $save=new ReflectionMethod(Organization::class,'saveProfitShare');
    $capSite=array_merge($site,['settings'=>json_encode(['max_profit_share_rate'=>80])]);
    $result=$save->invoke($controller,$request(['share_rate'=>80,'max_share_rate'=>80]),$capSite,$child,$root);
    $check($result['share_rate']==='80.0000','Valid site cap rejected');
    foreach([[-1,80],[81,81],[50,40],[0,101]] as [$actual,$maximum]){
        $rejected=false;
        try{$save->invoke($controller,$request(['share_rate'=>$actual,'max_share_rate'=>$maximum]),$capSite,$child,$root);}
        catch(InvalidArgumentException $e){$rejected=true;}
        $check($rejected,'Invalid individual share accepted');
    }
    foreach([[$site,$root+1000000],[array_merge($site,['id'=>0]),$root]] as [$scope,$parent]){
        $rejected=false;
        try{$save->invoke($controller,$request(['share_rate'=>20,'max_share_rate'=>80]),$scope,$child,$parent);}
        catch(InvalidArgumentException $e){$rejected=true;}
        $check($rejected,'Invalid parent/site accepted');
    }
});
$after=hash('sha256',json_encode(Db::name('organization_profit_shares')->where('site_id',$siteId)->order('id')->select()->toArray()));
$check($before===$after,'Existing site shares changed during verification');
echo 'Existing site shares unchanged; all fixtures rolled back'.PHP_EOL;
echo count($failures).' failed scenarios; '.$checks.' assertions'.PHP_EOL;
exit($failures===[]?0:1);
