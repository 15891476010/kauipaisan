<?php
declare(strict_types=1);
use think\facade\Db;
require __DIR__.'/vendor/autoload.php';
$app=new think\App();$app->initialize();

echo "=== distinct users in report_member_issue site15 (my inserted data) ===\n";
$u=Db::query("SELECT m.user_id, u.username, u.display_name, u.organization_id, COUNT(*) c, SUM(m.amount) amt, SUM(m.win_amount) win
              FROM report_member_issue m JOIN site_users u ON u.id=m.user_id
              WHERE m.site_id=15 GROUP BY m.user_id ORDER BY amt DESC");
printf("distinct members: %d\n",count($u));
foreach($u as $r) printf("  uid=%d user=%s disp=%s org=%d rows=%d amt=%.0f win=%.0f\n",
  $r['user_id'],$r['username'],$r['display_name']??'',$r['organization_id'],$r['c'],$r['amt'],$r['win']);

echo "\n=== all 26 members under director 38 (site_users) ===\n";
$node=Db::query("SELECT path FROM organization_nodes WHERE id=38")[0]['path'];
$orgs=Db::query("SELECT id FROM organization_nodes WHERE site_id=15 AND path LIKE ? AND deleted_at IS NULL",[$node.'%']);
$in=implode(',',array_map(fn($x)=>(int)$x['id'],$orgs));
$mem=Db::query("SELECT id,username,display_name,organization_id FROM site_users WHERE site_id=15 AND organization_id IN ($in) ORDER BY id");
foreach($mem as $r) printf("  uid=%d user=%s disp=%s org=%d\n",$r['id'],$r['username'],$r['display_name']??'',$r['organization_id']);

echo "\n=== raw bet_records: distinct users + username (June) ===\n";
$bu=Db::query("SELECT r.user_id,u.username,u.display_name,COUNT(*) c,SUM(r.amount) amt
               FROM bet_records r JOIN site_users u ON u.id=r.user_id
               WHERE r.site_id=15 AND r.placed_at>='2026-06-01' AND r.placed_at<'2026-07-01'
               GROUP BY r.user_id ORDER BY amt DESC LIMIT 30");
printf("June raw distinct bettors: %d\n",count($bu));
foreach($bu as $r) printf("  uid=%d user=%s disp=%s recs=%d amt=%.0f\n",$r['user_id'],$r['username'],$r['display_name']??'',$r['c'],$r['amt']);
