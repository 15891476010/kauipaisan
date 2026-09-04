<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

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
        $createdAgentId = (int)Db::name('agent_import_records')->where('batch_id',$batchId)->where('action','created_node')->order('id asc')->value('local_id');
        $now = date('Y-m-d H:i:s');

        // Create agent rows first so level-less rows can be attached to the
        // first imported agent regardless of the source API's ordering.
        usort($rows, static function ($left, $right): int {
            $leftHas=trim((string)(is_array($left)?($left['al']??''):''))!=='';
            $rightHas=trim((string)(is_array($right)?($right['al']??''):''))!=='';
            return ($rightHas<=>$leftHas);
        });
        foreach ($rows as $source) {
            if (!is_array($source)) { $stats['skipped']++; continue; }
            $externalId = trim((string)($source['ai'] ?? $source['id'] ?? ''));
            $username = trim((string)($source['an'] ?? $source['username'] ?? ''));
            if ($externalId === '' || $username === '') { $stats['skipped']++; continue; }

            $already = Db::name('agent_import_records')->where('entity_type', 'account')
                ->where('external_id', $externalId)->whereIn('action', ['created_node','created_member','reused'])
                ->order('id desc')->find();
            if ($already) { $stats['existing']++; continue; }

            // Include soft-deleted accounts: rollback keeps unique keys, so
            // blindly inserting the same username turns the next batch into
            // create_error and leaves its credentials empty. Restore that
            // account with a fresh initial password and return the password.
            $orgAccount = Db::name('organization_accounts')->where('site_id', $siteId)->where('username', $username)->find();
            $siteUser = Db::name('site_users')->where('site_id', $siteId)->where('username', $username)->find();
            if ($orgAccount || $siteUser) {
                $deleted = (string)($orgAccount['deleted_at'] ?? $siteUser['deleted_at'] ?? '') !== '';
                if ($deleted) {
                    $password = PasswordPolicy::initial('', $username);
                    if ($orgAccount) {
                        $orgId=(int)($orgAccount['organization_id']??0);
                        Db::name('organization_accounts')->where('id',$orgAccount['id'])->update(['deleted_at'=>null,'status'=>1,'password'=>password_hash($password,PASSWORD_DEFAULT),'must_change_password'=>1,'updated_at'=>$now]);
                        if($orgId>0) Db::name('organization_nodes')->where('id',$orgId)->update(['deleted_at'=>null,'status'=>1,'updated_at'=>$now]);
                        $credentials[]=['type'=>'agent','username'=>$username,'initial_password'=>$password,'node_id'=>$orgId];
                    } else {
                        Db::name('site_users')->where('id',$siteUser['id'])->update(['deleted_at'=>null,'status'=>1,'account_state'=>'enabled','password'=>password_hash($password,PASSWORD_DEFAULT),'must_change_password'=>1,'updated_at'=>$now]);
                        $credentials[]=['type'=>'member','username'=>$username,'initial_password'=>$password,'user_id'=>(int)$siteUser['id']];
                    }
                }
                $stats['existing']++;
                Db::name('agent_import_records')->insert([
                    'batch_id'=>$batchId, 'entity_type'=>'account', 'external_id'=>$externalId,
                    'local_id'=>(int)($orgAccount['id'] ?? $siteUser['id']), 'action'=>'reused',
                    'payload'=>json_encode(['username'=>$username,'source'=>$source,'restored'=>$deleted], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), 'created_at'=>$now,
                ]);
                continue;
            }

            try {
                // Numeric external levels are treated as subordinate agents.
                // Rows without a level are members of the first imported agent.
                $hasLevel = trim((string)($source['al'] ?? '')) !== '';
                if ($hasLevel && (string)$target['level'] !== 'agent') {
                    $nodeData = [
                        'tenant_id'=>$tenantId, 'site_id'=>$siteId, 'parent_id'=>(int)$target['id'],
                        'level'=>'agent', 'depth'=>(int)$target['depth']+1, 'path'=>'',
                        'name'=>$username, 'code'=>'IMP-'.$siteId.'-'.$batchId.'-'.preg_replace('/[^A-Za-z0-9_-]/','', $externalId),
                        'credit_limit'=>'0.00', 'balance'=>'0.00',
                        'permissions'=>json_encode(AgentAuthorization::sitePermissions($siteId,'agent'), JSON_UNESCAPED_UNICODE),
                        'settings'=>json_encode(['import_batch_id'=>$batchId,'external_id'=>$externalId], JSON_UNESCAPED_UNICODE),
                        'status'=>(int)($source['st'] ?? 1) === 0 ? 0 : 1, 'created_at'=>$now, 'updated_at'=>$now,
                    ];
                    $nodeId=(int)Db::name('organization_nodes')->insertGetId($nodeData);
                    $node=array_merge($nodeData,['id'=>$nodeId]);
                    OrganizationHierarchy::rebuildPath($nodeId);
                    $password=PasswordPolicy::initial('', $username);
                    $accountId=(int)Db::name('organization_accounts')->insertGetId([
                        'tenant_id'=>$tenantId,'site_id'=>$siteId,'organization_id'=>$nodeId,'username'=>$username,
                        'display_name'=>$username,'phone'=>'','password'=>password_hash($password,PASSWORD_DEFAULT),
                        'must_change_password'=>1,'permissions'=>$nodeData['permissions'],'status'=>1,'created_at'=>$now,'updated_at'=>$now,
                    ]);
                    Db::name('agent_import_records')->insert(['batch_id'=>$batchId,'entity_type'=>'account','external_id'=>$externalId,'local_id'=>$nodeId,'action'=>'created_node','payload'=>json_encode(['account_id'=>$accountId,'username'=>$username,'source'=>$source],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>$now]);
                    Db::name('agent_import_records')->insert(['batch_id'=>$batchId,'entity_type'=>'account_login','external_id'=>$externalId,'local_id'=>$accountId,'action'=>'created_account','payload'=>json_encode(['username'=>$username,'node_id'=>$nodeId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>$now]);
                    $createdAgentId=$createdAgentId ?: $nodeId;
                    $stats['created_nodes']++; $stats['created_accounts']++;
                    $credentials[]=['type'=>'agent','username'=>$username,'initial_password'=>$password,'node_id'=>$nodeId];
                    continue;
                }

                $memberOrgId = (string)$target['level'] === 'agent' ? (int)$target['id'] : $createdAgentId;
                if ($memberOrgId < 1) { $stats['skipped']++; continue; }
                $password=PasswordPolicy::initial('', $username);
                $memberId=(int)Db::name('site_users')->insertGetId([
                    'tenant_id'=>$tenantId,'site_id'=>$siteId,'organization_id'=>$memberOrgId,'username'=>$username,
                    'display_name'=>$username,'remark'=>'总代理做账导入批次 '.$batchId,'phone'=>'','balance'=>'0.00','credit_balance'=>'0.00','used_balance'=>'0.00',
                    'interception_rate'=>'0.0000','password'=>password_hash($password,PASSWORD_DEFAULT),'must_change_password'=>1,'status'=>1,'account_state'=>'enabled','created_at'=>$now,'updated_at'=>$now,
                ]);
                Db::name('agent_import_records')->insert(['batch_id'=>$batchId,'entity_type'=>'account','external_id'=>$externalId,'local_id'=>$memberId,'action'=>'created_member','payload'=>json_encode(['username'=>$username,'organization_id'=>$memberOrgId,'source'=>$source],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>$now]);
                $stats['created_members']++; $credentials[]=['type'=>'member','username'=>$username,'initial_password'=>$password,'user_id'=>$memberId];
            } catch (\Throwable $e) {
                $stats['failed']++;
                Db::name('agent_import_records')->insert(['batch_id'=>$batchId,'entity_type'=>'account','external_id'=>$externalId,'local_id'=>null,'action'=>'create_error','payload'=>json_encode(['username'=>$username,'error'=>mb_substr($e->getMessage(),0,500)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>$now]);
            }
        }
        return ['stats'=>$stats,'credentials'=>$credentials];
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
