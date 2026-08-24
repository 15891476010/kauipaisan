<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class AgentAuthorization
{
    public const LEVELS = [
        'director'=>'总监',
        'shareholder'=>'大股东',
        'small_shareholder'=>'小股东',
        'general_agent'=>'总代理',
        'agent'=>'代理',
    ];

    public const TREE = [
        ['code'=>'route.overview','label'=>'总货概览','type'=>'route','children'=>[
            ['code'=>'overview','label'=>'总货概览','type'=>'page'],
            ['code'=>'order_details','label'=>'总货明细','type'=>'page'],
            ['code'=>'winning_details','label'=>'中奖明细','type'=>'page'],
            ['code'=>'bet_details','label'=>'投注明细','type'=>'page'],
            ['code'=>'refunds','label'=>'查看退码','type'=>'page'],
        ]],
        ['code'=>'route.ledger','label'=>'贡献度 / 分类账','type'=>'route','children'=>[
            ['code'=>'contribution','label'=>'贡献度','type'=>'page'],
            ['code'=>'daily_ledger','label'=>'日分类账','type'=>'page'],
            ['code'=>'monthly_ledger','label'=>'月分类账','type'=>'page'],
            ['code'=>'daily_path','label'=>'日路径账','type'=>'page'],
            ['code'=>'monthly_path','label'=>'月路径账','type'=>'page'],
        ]],
        ['code'=>'route.reports','label'=>'报表','type'=>'route','children'=>[
            ['code'=>'reports','label'=>'综合报表','type'=>'page'],
            ['code'=>'monthly_reports','label'=>'月报表','type'=>'page'],
        ]],
        ['code'=>'route.results','label'=>'开奖号码','type'=>'route','children'=>[
            ['code'=>'results','label'=>'查看开奖号码','type'=>'page'],
        ]],
        ['code'=>'route.organizations','label'=>'组织架构','type'=>'route','children'=>[
            ['code'=>'organization.manage','label'=>'查看组织及下级','type'=>'page'],
            ['code'=>'organization.create','label'=>'新增下级','type'=>'button'],
            ['code'=>'organization.update','label'=>'修改下级','type'=>'button'],
            ['code'=>'organization.delete','label'=>'删除下级','type'=>'button'],
        ]],
        ['code'=>'route.subordinates','label'=>'会员 / 下级管理','type'=>'route','children'=>[
            ['code'=>'subordinates','label'=>'查看会员列表','type'=>'page'],
            ['code'=>'member.create','label'=>'新增会员','type'=>'button'],
            ['code'=>'member.update','label'=>'修改会员','type'=>'button'],
        ]],
        ['code'=>'route.intercept','label'=>'拦货','type'=>'route','children'=>[
            ['code'=>'interception_details','label'=>'拦货明细','type'=>'page'],
            ['code'=>'interception_winning','label'=>'拦货中奖','type'=>'page'],
            ['code'=>'interception_plate','label'=>'拦货盘面','type'=>'page'],
        ]],
        ['code'=>'route.logs','label'=>'日志','type'=>'route','children'=>[
            ['code'=>'logs','label'=>'查看日志','type'=>'page'],
        ]],
        ['code'=>'route.rules','label'=>'规则说明','type'=>'route','children'=>[
            ['code'=>'rules','label'=>'查看规则','type'=>'page'],
        ]],
        ['code'=>'route.settings','label'=>'业务设置','type'=>'route','children'=>[
            ['code'=>'settings','label'=>'查看业务设置','type'=>'page'],
            ['code'=>'settings.update','label'=>'保存业务设置','type'=>'button'],
        ]],
        ['code'=>'route.subaccounts','label'=>'子账号','type'=>'route','children'=>[
            ['code'=>'subaccounts','label'=>'查看子账号','type'=>'page'],
            ['code'=>'subaccount.create','label'=>'新建子账号','type'=>'button'],
            ['code'=>'subaccount.update','label'=>'修改子账号','type'=>'button'],
            ['code'=>'subaccount.delete','label'=>'删除子账号','type'=>'button'],
        ]],
    ];

    public static function codes(): array
    {
        $codes=[];
        $walk=static function(array $nodes)use(&$codes,&$walk):void{
            foreach($nodes as $node){$codes[]=(string)$node['code'];$walk($node['children']??[]);}
        };
        $walk(self::TREE);
        return $codes;
    }

    public static function codesForLevel(string $level): array
    {
        if(!isset(self::LEVELS[$level]))return [];
        // SaaS platform selects permissions centrally; every level can receive
        // the same route tree and the backend decides what to enable.
        return self::codes();
    }

    public static function normalize(mixed $value): array
    {
        $items=is_array($value)?array_map('strval',$value):[];
        if(in_array('*',$items,true))return ['*'];
        $selected=array_flip(array_values(array_unique($items)));
        $normalized=[];
        foreach(self::TREE as $route){
            $routeCode=(string)$route['code'];
            if(!isset($selected[$routeCode]))continue;
            $normalized[]=$routeCode;
            foreach($route['children']??[] as $child){if(isset($selected[(string)$child['code']]))$normalized[]=(string)$child['code'];}
        }
        return $normalized;
    }

    public static function normalizeForLevel(mixed $value,string $level): array
    {
        $allowed=self::codesForLevel($level);
        $items=is_array($value)&&in_array('*',$value,true)?$allowed:self::expandLegacyRoutes(is_array($value)?$value:[]);
        return array_values(array_intersect($items,$allowed));
    }

    public static function expandLegacyRoutes(array $permissions): array
    {
        if(in_array('*',$permissions,true))return ['*'];
        $items=array_values(array_unique(array_map('strval',$permissions)));
        $hasRoute=false;
        foreach($items as $item){if(str_starts_with($item,'route.')){$hasRoute=true;break;}}
        if(!$hasRoute){
            if(count(array_intersect(['overview','order_details','winning_details','bet_details'],$items))===4)$items[]='refunds';
            if(in_array('organization.manage',$items,true))$items=array_merge($items,['organization.create','organization.update','organization.delete']);
            if(in_array('subordinates',$items,true))$items=array_merge($items,['member.create','member.update']);
            if(in_array('settings',$items,true))$items[]='settings.update';
            if(in_array('subaccounts',$items,true))$items=array_merge($items,['subaccount.create','subaccount.update','subaccount.delete']);
        }
        foreach(self::TREE as $route){
            foreach($route['children']??[] as $child){
                if(in_array((string)$child['code'],$items,true)){$items[]=(string)$route['code'];break;}
            }
        }
        return self::normalize(array_values(array_unique($items)));
    }

    private static function siteSettings(int $siteId): ?array
    {
        if($siteId<1)return null;
        $site=Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->field('settings')->find();
        if(!$site)return null;
        $settings=$site['settings']??null;
        $settings=is_string($settings)?json_decode($settings,true):(is_array($settings)?$settings:[]);
        return is_array($settings)?$settings:[];
    }

    public static function sitePermissionsByLevel(int $siteId): array
    {
        $settings=self::siteSettings($siteId);
        if($settings===null)return array_fill_keys(array_keys(self::LEVELS),[]);
        $configured=$settings['agent_permissions_by_level']??null;
        $legacy=array_key_exists('agent_permissions',$settings)?$settings['agent_permissions']:['*'];
        $permissions=[];
        foreach(self::LEVELS as $level=>$label){
            $value=is_array($configured)&&array_key_exists($level,$configured)?$configured[$level]:$legacy;
            $permissions[$level]=self::normalizeForLevel($value,$level);
        }
        return $permissions;
    }

    public static function sitePermissions(int $siteId,string $level): array
    {
        return self::sitePermissionsByLevel($siteId)[$level]??[];
    }

    public static function intersect(array $permissions,array $sitePermissions): array
    {
        if(in_array('*',$sitePermissions,true))return $permissions;
        if(in_array('*',$permissions,true))return $sitePermissions;
        return array_values(array_intersect($permissions,$sitePermissions));
    }
}
