<?php
declare(strict_types=1);
use think\facade\Db;
require __DIR__.'/vendor/autoload.php';
$app=new think\App();$app->initialize();

echo "=== sites (id, name, code) ===\n";
foreach(Db::name('sites')->field('id,name,code,status,manager_username')->select()->toArray() as $s)
  printf("  site %d  name=%s  code=%s status=%s mgr=%s\n",$s['id'],$s['name']??'',$s['code']??'',$s['status']??'',$s['manager_username']??'');

echo "\n=== users named qkk% (which site?) ===\n";
$q=Db::query("SELECT site_id, COUNT(*) c FROM site_users WHERE username LIKE 'qkk%' GROUP BY site_id");
foreach($q as $r) printf("  site %d: %d qkk-users\n",$r['site_id'],$r['c']);
$sample=Db::query("SELECT id,site_id,username,organization_id FROM site_users WHERE username LIKE 'qkk%' ORDER BY id LIMIT 8");
foreach($sample as $r) printf("    uid=%d site=%d user=%s org=%d\n",$r['id'],$r['site_id'],$r['username'],$r['organization_id']);

echo "\n=== director-level nodes per site (level=director) ===\n";
foreach(Db::query("SELECT id,site_id,parent_id,level,name FROM organization_nodes WHERE level='director' AND deleted_at IS NULL ORDER BY site_id") as $r)
  printf("  dir org=%d site=%d parent=%d name=%s\n",$r['id'],$r['site_id'],$r['parent_id'],$r['name']??'');

echo "\n=== report_member_issue row counts per site ===\n";
foreach(Db::query("SELECT site_id, COUNT(*) c, MIN(day) mn, MAX(day) mx FROM report_member_issue GROUP BY site_id") as $r)
  printf("  site %d: %d rows  %s..%s\n",$r['site_id'],$r['c'],$r['mn'],$r['mx']);

echo "\n=== bet_records per site (raw) ===\n";
foreach(Db::query("SELECT site_id, COUNT(*) c, MIN(placed_at) mn, MAX(placed_at) mx FROM bet_records GROUP BY site_id") as $r)
  printf("  site %d: %d recs  %s..%s\n",$r['site_id'],$r['c'],$r['mn'],$r['mx']);

echo "\n=== agent_import_batches (imported overview source) ===\n";
foreach(Db::query("SELECT id,site_id,tenant_id,status,from_date,to_date,target_organization_id FROM agent_import_batches ORDER BY id DESC LIMIT 20") as $r)
  printf("  batch %d site=%d status=%s %s..%s target_org=%d\n",$r['id'],$r['site_id'],$r['status'],$r['from_date'],$r['to_date'],$r['target_organization_id']);
