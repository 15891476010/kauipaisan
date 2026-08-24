<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));
$app->initialize();

use app\controller\Organization;
use app\service\OrganizationHierarchy;
use think\Request;
use think\facade\Cache;
use think\facade\Db;

$nodes=Db::name('organization_nodes')->whereNull('deleted_at')->order('site_id asc,id asc')->select()->toArray();
$children=[];
foreach($nodes as $node)$children[(int)$node['parent_id']][]=$node;
$root=null;
foreach($nodes as $node){if((int)$node['parent_id']>0&&!empty($children[(int)$node['id']])){$root=$node;break;}}
if(!$root)throw new RuntimeException('缺少可用于组织下钻权限测试的非根组织链');
$child=$children[(int)$root['id']][0];
$token='organization-drill-test-'.bin2hex(random_bytes(8));
Cache::set('token:'.$token,['scope'=>'agent','site_id'=>(int)$root['site_id'],'tenant_id'=>(int)$root['tenant_id'],'organization_id'=>(int)$root['id'],'user_id'=>0,'username'=>'下钻测试账号'],300);
$request=static fn(int $organizationId):Request=>(new Request())->withHeader(['authorization'=>'Bearer '.$token])->withGet(['organization_id'=>$organizationId]);
$controller=new Organization();
$check=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$decode=static function($response):array{return json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);};
try{
    $payload=$decode($controller->agentIndex($request((int)$child['id'])));
    $data=$payload['data']??[];
    $check((int)($data['current']['id']??0)===(int)$child['id'],'未进入指定直属下级');
    $check(($data['current']['can_manage']??true)===false,'后代浏览必须为只读');
    $check((int)($data['breadcrumbs'][0]['id']??0)===(int)$root['id'],'面包屑泄露了当前账号上级');
    $check((int)($data['breadcrumbs'][count($data['breadcrumbs'])-1]['id']??0)===(int)$child['id'],'面包屑未定位当前节点');
    foreach($data['nodes']??[] as $row)$check((int)$row['parent_id']===(int)$child['id'],'列表混入非直属下级');
    $denied=false;
    try{$controller->agentIndex($request((int)$root['parent_id']));}catch(InvalidArgumentException $error){$denied=str_contains($error->getMessage(),'无权查看');}
    $check($denied,'通过 organization_id 越权查看上级未被拒绝');
    $agent=null;
    $visible=OrganizationHierarchy::descendantIds((int)$root['id']);
    foreach($nodes as $node)if(in_array((int)$node['id'],$visible,true)&&(string)$node['level']==='agent'){$agent=$node;break;}
    if($agent){
        $agentData=($decode($controller->agentIndex($request((int)$agent['id'])))['data']??[]);
        foreach($agentData['members']??[] as $member)$check((int)$member['organization_id']===(int)$agent['id'],'代理列表混入其他代理会员');
    }
}finally{Cache::delete('token:'.$token);}

echo "Organization drill-down tests passed\n";
