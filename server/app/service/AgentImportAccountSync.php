<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use app\service\ScoreTransfer;

/**
 * Materializes the account snapshot produced by AgentImport into the local
 * organization tree. External passwords are never available in the source
 * response, so locally generated initial passwords are returned once to the
 * caller and are not persisted in the import records.
 */
final class AgentImportAccountSync
{
    public static function import(int $batchId, int $tenantId, int $siteId, int $targetOrganizationId, array $rows): array
    {
        $target = Db::name('organization_nodes')->where('id', $targetOrganizationId)
            ->where('tenant_id', $tenantId)->where('site_id', $siteId)->whereNull('deleted_at')->find();
        if (!$target) throw new \InvalidArgumentException('写入目标组织不存在');

        $stats = ['created_nodes' => 0, 'created_accounts' => 0, 'created_members' => 0, 'existing' => 0, 'skipped' => 0, 'failed' => 0];
        $credentials = [];
        $now = date('Y-m-d H:i:s');

        // The source account API returns a flat page per parent.  `pi` is the
        // external parent id and `tp` is the account type (1..5 are hierarchy
        // nodes, 6 is a member).  Build the external->local map first, then
        // attach every row to its real parent.  This works regardless of
        // whether the selected target is a director, shareholder, general
        // agent, or agent; no level is hard-coded to “总代理”.
        $pending=[]; foreach($rows as $source){
            if(!is_array($source)){$stats['skipped']++;continue;}
            $externalId=trim((string)($source['ai']??$source['id']??''));
            $username=trim((string)($source['an']??$source['username']??''));
            if($externalId===''||$username===''){$stats['skipped']++;continue;}
            $pending[$externalId]=$source;
        }
        uasort($pending,static function(array $a,array $b): int {
            $ta=(int)($a['tp']??6);$tb=(int)($b['tp']??6);
            return $ta<=>$tb;
        });
        $externalNodes=[];$externalMembers=[];
        // Reuse nodes created by an earlier batch by their persisted external
        // id, even when that earlier batch belongs to a different import id.
        foreach(Db::name('organization_nodes')->where('site_id',$siteId)->where('tenant_id',$tenantId)->whereNull('deleted_at')->field('id,settings')->select()->toArray() as $node){$settings=json_decode((string)($node['settings']??''),true);$ext=trim((string)($settings['external_id']??''));if($ext!=='')$externalNodes[$ext]=(int)$node['id'];}
        foreach ($pending as $source) {
            $externalId = trim((string)($source['ai'] ?? $source['id'] ?? ''));
            $username = trim((string)($source['an'] ?? $source['username'] ?? ''));
            $sourceType=(int)($source['tp']??6);
            $parentExternal=trim((string)($source['pi']??''));
            $parentId=(int)($externalNodes[$parentExternal]??$target['id']);

            $already = Db::name('agent_import_records')->where('entity_type', 'account')
                ->where('batch_id',$batchId)
                ->where('external_id', $externalId)->whereIn('action', ['created_node','created_member','reused'])
                ->order('id desc')->find();
            if ($already) {
                $stats['existing']++;
                $payload=json_decode((string)($already['payload']??''),true);
                $localId=(int)($already['local_id']??0);
                if($sourceType>=6){$member=Db::name('site_users')->where('id',$localId)->where('site_id',$siteId)->find();if($member){if($parentId>0&&str_contains((string)($member['remark']??''),'做账导入')&&(int)$member['organization_id']!==$parentId){Db::name('site_users')->where('id',$localId)->update(['organization_id'=>$parentId,'updated_at'=>$now]);$externalMembers[$externalId]=$parentId;}else $externalMembers[$externalId]=(int)$member['organization_id'];}}
                else {$node=Db::name('organization_nodes')->where('id',$localId)->where('site_id',$siteId)->find();if($node)$externalNodes[$externalId]=(int)$node['id'];}
                continue;
            }

            // Include soft-deleted rows: the import cleanup keeps the unique
            // username reservation, so inserting a fresh row would fail with
            // a duplicate-key error. Reactivate and reuse the old row instead.
            $orgAccount = Db::name('organization_accounts')->where('site_id', $siteId)->where('username', $username)->order('id desc')->find();
            $siteUser = Db::name('site_users')->where('site_id', $siteId)->where('username', $username)->order('id desc')->find();
            if ($orgAccount || $siteUser) {
                if ($orgAccount && !empty($orgAccount['deleted_at'])) {
                    Db::name('organization_accounts')->where('id',(int)$orgAccount['id'])->update(['deleted_at'=>null,'status'=>1,'updated_at'=>$now]);
                    $orgNodeId=(int)($orgAccount['organization_id']??0);
                    if($orgNodeId>0) Db::name('organization_nodes')->where('id',$orgNodeId)->where('tenant_id',$tenantId)->where('site_id',$siteId)->update(['deleted_at'=>null,'status'=>1,'updated_at'=>$now]);
                    $orgAccount['deleted_at']=null;
                }
                if ($siteUser && !empty($siteUser['deleted_at'])) {
                    Db::name('site_users')->where('id',(int)$siteUser['id'])->update(['deleted_at'=>null,'status'=>1,'account_state'=>'enabled','updated_at'=>$now]);
                    $siteUser['deleted_at']=null;
                }
                $stats['existing']++;
                $existingLocal=(int)($orgAccount['organization_id']??$siteUser['organization_id']??0);
                if($sourceType>=6 && $siteUser && $parentId>0 && (int)$siteUser['organization_id']!==$parentId && str_contains((string)($siteUser['remark']??''),'做账导入')){
                    Db::name('site_users')->where('id',(int)$siteUser['id'])->update(['organization_id'=>$parentId,'updated_at'=>$now]);
                    $existingLocal=$parentId;
                }
                Db::name('agent_import_records')->insert([
                    'batch_id'=>$batchId, 'entity_type'=>'account', 'external_id'=>$externalId,
                    'local_id'=>(int)($orgAccount['id'] ?? $siteUser['id']), 'action'=>'reused',
                    'payload'=>json_encode(['username'=>$username,'source'=>$source], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), 'created_at'=>$now,
                ]);
                if($sourceType>=6)$externalMembers[$externalId]=$existingLocal;else $externalNodes[$externalId]=$existingLocal;
                continue;
            }

            try {
                $type=$sourceType;
                if ($type < 6) {
                    $level=self::levelForType($type,(string)$target['level']);
                    $nodeData = [
                        'tenant_id'=>$tenantId, 'site_id'=>$siteId, 'parent_id'=>$parentId,
                        'level'=>$level, 'depth'=>(int)$target['depth']+1, 'path'=>'',
                        'name'=>$username, 'code'=>'IMP-'.$siteId.'-'.$batchId.'-'.preg_replace('/[^A-Za-z0-9_-]/','', $externalId),
                        // `ob` is the source account's credit quota. Keep it
                        // on the corresponding local node so the hierarchy
                        // view and later score allocation use the same value.
                        'credit_limit'=>number_format(max(0,(float)($source['ob']??0)),2,'.',''), 'balance'=>'0.00',
                        'permissions'=>json_encode(AgentAuthorization::sitePermissions($siteId,$level), JSON_UNESCAPED_UNICODE),
                        'settings'=>json_encode(['import_batch_id'=>$batchId,'external_id'=>$externalId], JSON_UNESCAPED_UNICODE),
                        'status'=>(int)($source['st'] ?? 1) === 0 ? 0 : 1, 'created_at'=>$now, 'updated_at'=>$now,
                    ];
                    $nodeId=(int)Db::name('organization_nodes')->insertGetId($nodeData);
                    $node=array_merge($nodeData,['id'=>$nodeId]);
                    OrganizationHierarchy::rebuildPath($nodeId);
                    $shareRate=max(0,(float)($source['or']??0));
                    Db::name('organization_profit_shares')->insert(['tenant_id'=>$tenantId,'site_id'=>$siteId,'parent_organization_id'=>$parentId,'child_organization_id'=>$nodeId,'max_share_rate'=>number_format(max($shareRate,(float)($source['mr']??$shareRate)),4,'.',''),'share_rate'=>number_format($shareRate,4,'.',''),'status'=>1,'effective_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
                    $credit=(float)($source['ob']??0);
                    if($credit>0){
                        try { self::ensureOrganizationBalance($parentId,$credit,$tenantId,$siteId); ScoreTransfer::organizationAllocation($node,$credit,['source'=>'agent_import','batch_id'=>$batchId]); }
                        catch(\Throwable $allocationError){ Db::name('agent_import_records')->insert(['batch_id'=>$batchId,'entity_type'=>'account','external_id'=>$externalId,'local_id'=>$nodeId,'action'=>'allocation_error','payload'=>json_encode(['credit_limit'=>$credit,'error'=>$allocationError->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>$now]); }
                    }
                    $password=PasswordPolicy::initial('', $username);
                    $accountId=(int)Db::name('organization_accounts')->insertGetId([
                        'tenant_id'=>$tenantId,'site_id'=>$siteId,'organization_id'=>$nodeId,'username'=>$username,
                        'display_name'=>$username,'phone'=>'','password'=>password_hash($password,PASSWORD_DEFAULT),
                        'must_change_password'=>1,'permissions'=>$nodeData['permissions'],'status'=>1,'created_at'=>$now,'updated_at'=>$now,
                    ]);
                    Db::name('agent_import_records')->insert(['batch_id'=>$batchId,'entity_type'=>'account','external_id'=>$externalId,'local_id'=>$nodeId,'action'=>'created_node','payload'=>json_encode(['account_id'=>$accountId,'username'=>$username,'source'=>$source],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>$now]);
                    Db::name('agent_import_records')->insert(['batch_id'=>$batchId,'entity_type'=>'account_login','external_id'=>$externalId,'local_id'=>$accountId,'action'=>'created_account','payload'=>json_encode(['username'=>$username,'node_id'=>$nodeId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>$now]);
                    $externalNodes[$externalId]=$nodeId;
                    $stats['created_nodes']++; $stats['created_accounts']++;
                    $credentials[]=['type'=>$level,'username'=>$username,'initial_password'=>$password,'node_id'=>$nodeId];
                    continue;
                }

                $memberOrgId = $parentId;
                if ($memberOrgId < 1) { $stats['skipped']++; continue; }
                $password=PasswordPolicy::initial('', $username);
                $memberId=(int)Db::name('site_users')->insertGetId([
                    'tenant_id'=>$tenantId,'site_id'=>$siteId,'organization_id'=>$memberOrgId,'username'=>$username,
                    'display_name'=>$username,'remark'=>'总代理做账导入批次 '.$batchId,'phone'=>'','balance'=>'0.00','credit_balance'=>'0.00','used_balance'=>'0.00',
                    'interception_rate'=>'0.0000','password'=>password_hash($password,PASSWORD_DEFAULT),'must_change_password'=>1,'status'=>1,'account_state'=>'enabled','created_at'=>$now,'updated_at'=>$now,
                ]);
                Db::name('agent_import_records')->insert(['batch_id'=>$batchId,'entity_type'=>'account','external_id'=>$externalId,'local_id'=>$memberId,'action'=>'created_member','payload'=>json_encode(['username'=>$username,'organization_id'=>$memberOrgId,'source'=>$source],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>$now]);
                $externalMembers[$externalId]=$memberOrgId;
                $stats['created_members']++; $credentials[]=['type'=>'member','username'=>$username,'initial_password'=>$password,'user_id'=>$memberId,'organization_id'=>$memberOrgId];
            } catch (\Throwable $e) {
                $stats['failed']++;
                Db::name('agent_import_records')->insert(['batch_id'=>$batchId,'entity_type'=>'account','external_id'=>$externalId,'local_id'=>null,'action'=>'create_error','payload'=>json_encode(['username'=>$username,'error'=>mb_substr($e->getMessage(),0,500)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>$now]);
            }
        }
        return ['stats'=>$stats,'credentials'=>$credentials];
    }

    private static function levelForType(int $type,string $targetLevel): string
    {
        $map=[1=>'director',2=>'shareholder',3=>'small_shareholder',4=>'general_agent',5=>'agent'];
        return $map[$type]??($targetLevel==='agent'?'agent':'agent');
    }

    private static function ensureOrganizationBalance(int $organizationId,float $amount,int $tenantId,int $siteId,array $visited=[]): void
    {
        if($organizationId<1||$amount<=0)return;if(isset($visited[$organizationId]))throw new \RuntimeException('组织层级存在循环');$visited[$organizationId]=true;
        $parent=Db::name('organization_nodes')->where('id',$organizationId)->where('tenant_id',$tenantId)->where('site_id',$siteId)->whereNull('deleted_at')->find();if(!$parent)throw new \RuntimeException('上级组织不存在');$missing=max(0,$amount-(float)$parent['balance']);if($missing<=0)return;
        $parentId=(int)$parent['parent_id'];if($parentId>0)self::ensureOrganizationBalance($parentId,$missing,$tenantId,$siteId,$visited);
        ScoreTransfer::organizationAllocation($parent,$missing,['source'=>'agent_import']);
    }

    public static function rollback(int $batchId, int $tenantId): array
    {
        $records=Db::name('agent_import_records')->where('batch_id',$batchId)->order('id desc')->select()->toArray();
        $removed=['accounts'=>0,'members'=>0,'nodes'=>0];
        foreach($records as $record){
            $id=(int)($record['local_id']??0); if($id<1) continue;
            $action=(string)$record['action'];
            if($action==='created_account'){$removed['accounts']+=Db::name('organization_accounts')->where('id',$id)->whereNull('deleted_at')->update(['deleted_at'=>date('Y-m-d H:i:s'),'status'=>0,'updated_at'=>date('Y-m-d H:i:s')]);}
            elseif($action==='created_member'){$removed['members']+=Db::name('site_users')->where('id',$id)->where('tenant_id',$tenantId)->whereNull('deleted_at')->update(['deleted_at'=>date('Y-m-d H:i:s'),'status'=>0,'account_state'=>'disabled','updated_at'=>date('Y-m-d H:i:s')]);}
            elseif($action==='created_node'){$removed['nodes']+=Db::name('organization_nodes')->where('id',$id)->where('tenant_id',$tenantId)->whereNull('deleted_at')->update(['deleted_at'=>date('Y-m-d H:i:s'),'status'=>0,'updated_at'=>date('Y-m-d H:i:s')]);}
        }
        Db::name('agent_import_records')->where('batch_id',$batchId)->whereIn('action',['created_account','created_member','created_node'])->update(['action'=>'rolled_back']);
        return $removed;
    }
}
