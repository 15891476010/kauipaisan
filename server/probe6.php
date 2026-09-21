<?php
declare(strict_types=1);
use think\facade\Db;
require __DIR__.'/vendor/autoload.php';
$app=new think\App();$app->initialize();

echo "=== site 16 overview ===\n";
foreach(['organization_nodes'=>"level='director' AND deleted_at IS NULL",'site_users'=>'deleted_at IS NULL'] as $t=>$w){}
$dirs=Db::query("SELECT id,site_id,parent_id,level,name FROM organization_nodes WHERE site_id=16 AND deleted_at IS NULL ORDER BY id");
echo "site16 nodes: ".count($dirs)."\n";
foreach($dirs as $r) printf("  org=%d parent=%d level=%s name=%s\n",$r['id'],$r['parent_id'],$r['level'],$r['name']??'');
$u16=Db::query("SELECT COUNT(*) c FROM site_users WHERE site_id=16");
printf("site16 site_users: %d\n",$u16[0]['c']);
$r16=Db::query("SELECT COUNT(*) c FROM report_member_issue WHERE site_id=16");
printf("site16 report_member_issue: %d\n",$r16[0]['c']);
$b16=Db::query("SELECT COUNT(*) c FROM bet_records WHERE site_id=16");
printf("site16 bet_records: %d\n",$b16[0]['c']);

echo "\n=== where do qkkNNN members appear? (site_users any site) ===\n";
foreach(Db::query("SELECT site_id,COUNT(*) c FROM site_users WHERE username REGEXP '^qkk[0-9]+' GROUP BY site_id") as $r)
  printf("  site %d: %d\n",$r['site_id'],$r['c']);

echo "\n=== members per director subtree (site 15) via organization path ===\n";
foreach([38,51,52] as $d){
  $node=Db::query("SELECT id,path FROM organization_nodes WHERE id=?",[$d]);
  if(!$node){echo "  dir $d missing\n";continue;}
  $path=$node[0]['path'];
  $orgs=Db::query("SELECT id FROM organization_nodes WHERE site_id=15 AND path LIKE ? AND deleted_at IS NULL",[$path.'%']);
  $ids=array_map(fn($x)=>(int)$x['id'],$orgs);
  $in=implode(',',$ids?:[0]);
  $mc=Db::query("SELECT COUNT(*) c FROM site_users WHERE site_id=15 AND organization_id IN ($in)");
  $rc=Db::query("SELECT COUNT(*) c FROM report_member_issue m JOIN site_users u ON u.id=m.user_id WHERE m.site_id=15 AND u.organization_id IN ($in)");
  printf("  dir %d (path=%s): orgs=%d members=%d report_rows(by member org)=%d\n",$d,$path,count($ids),$mc[0]['c'],$rc[0]['c']);
}

echo "\n=== agent_import_records report_overview member names (batch 46 completed) ===\n";
$recs=Db::query("SELECT payload FROM agent_import_records WHERE batch_id=46 AND entity_type='report_overview' ORDER BY id ASC LIMIT 3");
printf("  batch46 report_overview records: %d (showing sample names)\n",count($recs));
foreach($recs as $rec){
  $p=json_decode((string)$rec['payload'],true);
  $rl=$p['response']['data']['rl']??[];
  $names=[];foreach(array_slice((array)$rl,0,10) as $s){if(is_array($s))$names[]=($s['an']??'?').'/dn='.($s['dn']??'?');}
  echo "    ".implode(' , ',$names)."\n";
}
