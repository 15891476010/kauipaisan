<?php
declare(strict_types=1);
use think\migration\Seeder;
use think\facade\Db;

final class InitialSeeder extends Seeder
{
    public function run(): void
    {
        $now=date('Y-m-d H:i:s');
        if (!Db::name('tenants')->where('code','platform')->find()) Db::name('tenants')->insert(['name'=>'平台租户','code'=>'platform','created_at'=>$now,'updated_at'=>$now]);
        $tenant=(int)Db::name('tenants')->where('code','platform')->value('id');
        if (!Db::name('sites')->where('tenant_id',$tenant)->where('is_platform_site',1)->whereNull('deleted_at')->find()) Db::name('sites')->insert(['tenant_id'=>$tenant,'agent_id'=>0,'is_platform_site'=>1,'name'=>'平台自有站点','code'=>null,'status'=>1,'created_at'=>$now,'updated_at'=>$now]);
        if (!Db::name('users')->where('username','admin')->find()) Db::name('users')->insert(['tenant_id'=>$tenant,'username'=>'admin','display_name'=>'平台管理员','password'=>password_hash('admin123456',PASSWORD_DEFAULT),'user_type'=>'admin','created_at'=>$now,'updated_at'=>$now]);
        if (!Db::name('admins')->where('username','admin')->find()) Db::name('admins')->insert(['tenant_id'=>$tenant,'username'=>'admin','display_name'=>'平台管理员','password'=>password_hash('admin123456',PASSWORD_DEFAULT),'created_at'=>$now,'updated_at'=>$now]);
        if (!Db::name('users')->where('username','demo')->find()) Db::name('users')->insert(['tenant_id'=>$tenant,'username'=>'demo','display_name'=>'演示会员','password'=>password_hash('demo123456',PASSWORD_DEFAULT),'user_type'=>'member','created_at'=>$now,'updated_at'=>$now]);
        $menus=[['parent_id'=>0,'title'=>'数据看板','name'=>'dashboard','path'=>'/dashboard','component'=>'DashboardView','icon'=>'Grid','sort'=>1],['parent_id'=>0,'title'=>'一级代理','name'=>'agents','path'=>'/agents','component'=>'ResourceView','icon'=>'User','sort'=>10],['parent_id'=>0,'title'=>'二级代理','name'=>'sub-agents','path'=>'/sub-agents','component'=>'ResourceView','icon'=>'Connection','sort'=>20],['parent_id'=>0,'title'=>'站点管理','name'=>'sites','path'=>'/sites','component'=>'ResourceView','icon'=>'Monitor','sort'=>30],['parent_id'=>0,'title'=>'域名管理','name'=>'domains','path'=>'/domains','component'=>'ResourceView','icon'=>'Link','sort'=>40],['parent_id'=>0,'title'=>'管理员','name'=>'admins','path'=>'/admins','component'=>'ResourceView','icon'=>'User','sort'=>50],['parent_id'=>0,'title'=>'角色权限','name'=>'roles','path'=>'/roles','component'=>'ResourceView','icon'=>'Lock','sort'=>60],['parent_id'=>0,'title'=>'菜单管理','name'=>'menus','path'=>'/menus','component'=>'ResourceView','icon'=>'Menu','sort'=>70],['parent_id'=>0,'title'=>'审计日志','name'=>'audit-logs','path'=>'/audit-logs','component'=>'ResourceView','icon'=>'Document','sort'=>80],['parent_id'=>0,'title'=>'系统配置','name'=>'settings','path'=>'/settings','component'=>'ResourceView','icon'=>'Setting','sort'=>90]];
        foreach($menus as $menu) if(!Db::name('menus')->where('name',$menu['name'])->find()) Db::name('menus')->insert($menu);
        // Keep the platform navigation aligned with the ownership model:
        // one代理中心 owns both sites and their domains.
        Db::name('menus')->where('name','sub-agents')->update(['title'=>'代理中心','path'=>'/agent-center','icon'=>'Connection']);
        if (!Db::name('menus')->where('name','agent-center')->find()) Db::name('menus')->insert(['parent_id'=>0,'title'=>'代理中心','name'=>'agent-center','path'=>'/agent-center','component'=>'ResourceView','icon'=>'Connection','sort'=>20,'status'=>1]);
        if (!Db::name('menus')->where('name','site-users')->find()) Db::name('menus')->insert(['parent_id'=>0,'title'=>'站点用户','name'=>'site-users','path'=>'/site-users','component'=>'ResourceView','icon'=>'User','sort'=>30,'status'=>1]);
        if (!Db::name('menus')->where('name','bet-records')->find()) Db::name('menus')->insert(['parent_id'=>0,'title'=>'下单记录','name'=>'bet-records','path'=>'/bet-records','component'=>'ResourceView','icon'=>'List','sort'=>35,'status'=>1]);
        Db::name('menus')->whereIn('name',['agents','sub-agents'])->delete();
        Db::name('menus')->whereIn('name',['sites','domains'])->delete();
    }
}
