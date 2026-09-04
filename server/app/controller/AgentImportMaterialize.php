<?php
declare(strict_types=1);

namespace app\controller;

use app\service\AgentImportAccountSync;
use think\Request;
use think\facade\Cache;
use think\facade\Db;

/** Adds the local account materialization step to the existing snapshot import. */
final class AgentImportMaterialize
{
    private function session(Request $request): array
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token!==''?Cache::get('token:'.$token):null;
        if(!is_array($session)||($session['scope']??'')!=='admin') throw new \RuntimeException('未登录或登录已过期');
        return $session;
    }

    private function payload(mixed $response): array
    {
        $raw=method_exists($response,'getContent')?(string)$response->getContent():(string)$response;
        $decoded=json_decode($raw,true);
        return is_array($decoded)?$decoded:['code'=>502,'message'=>'做账接口没有返回有效响应','data'=>null];
    }

    private function retryAccounts(array $batch): array
    {
        $profile=Db::name('agent_import_profiles')->where('id',(int)$batch['profile_id'])->find();
        if(!$profile)return [];
        $controller=new AgentImport();$loginMethod=new \ReflectionMethod($controller,'login');$loginMethod->setAccessible(true);$callMethod=new \ReflectionMethod($controller,'call');$callMethod->setAccessible(true);
        for($attempt=1;$attempt<=3;$attempt++){
            try{$login=$loginMethod->invoke($controller,$profile);$result=$callMethod->invoke($controller,$profile['base_url'],$login['ak'],'ag.ac','gal',['pn'=>1,'ps'=>100,'lt'=>4]);$response=$result['response'];$data=$response['data']??[];$rows=(array)($data['al']??$data['list']??[]);if($rows){Db::name('agent_import_records')->insert(['batch_id'=>$batch['id'],'entity_type'=>'accounts','external_id'=>null,'local_id'=>null,'action'=>'snapshot_retry','payload'=>json_encode(['request'=>$result['request'],'response'=>$response,'attempt'=>$attempt],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>date('Y-m-d H:i:s')]);return $rows;}}
            catch(\Throwable $e){if($attempt===3)Db::name('agent_import_records')->insert(['batch_id'=>$batch['id'],'entity_type'=>'accounts','external_id'=>null,'local_id'=>null,'action'=>'retry_error','payload'=>json_encode(['error'=>$e->getMessage(),'attempts'=>$attempt],JSON_UNESCAPED_UNICODE),'created_at'=>date('Y-m-d H:i:s')]);}
        }
        return [];
    }

    /** Read the root and recursive account-tree snapshots saved by the worker. */
    private function accountRows(int $batchId): array
    {
        $out=[];$seen=[];
        $records=Db::name('agent_import_records')->where('batch_id',$batchId)->whereIn('entity_type',['accounts','account_tree'])->whereIn('action',['snapshot','snapshot_retry'])->order('id asc')->select()->toArray();
        foreach($records as $record){$body=json_decode((string)$record['payload'],true);$data=$body['response']['data']??[];$rows=(array)($data['al']??$data['list']??[]);foreach($rows as $row){if(!is_array($row))continue;$id=trim((string)($row['ai']??$row['id']??''));if($id===''||isset($seen[$id]))continue;$seen[$id]=true;$out[]=$row;}}
        return $out;
    }

    public function createBatch(Request $request): \think\response\Json
    {
        $session=$this->session($request);
        // Queue the long reference-site crawl in a detached worker. The
        // browser receives immediately and can refresh the batch list while
        // the worker performs login, snapshots and account materialization.
        if ((string)$request->header('x-agent-import-worker') !== '1') {
            $payload=$request->post();
            $siteId=(int)($payload['site_id']??0);$from=(string)($payload['from_date']??'');$to=(string)($payload['to_date']??'');$profileId=(int)($payload['profile_id']??0);$targetId=(int)($payload['target_organization_id']??0);
            if($siteId<1||!preg_match('/^\d{4}-\d\d-\d\d$/',$from)||!preg_match('/^\d{4}-\d\d-\d\d$/',$to)||$from>$to)return json(['code'=>422,'message'=>'做账参数无效','data'=>null],422);
            if(!Db::name('sites')->where('id',$siteId)->where('tenant_id',(int)$session['tenant_id'])->whereNull('deleted_at')->count())return json(['code'=>404,'message'=>'站点不存在','data'=>null],404);
            if(!Db::name('agent_import_profiles')->where('id',$profileId)->where('site_id',$siteId)->where('tenant_id',(int)$session['tenant_id'])->count())return json(['code'=>404,'message'=>'数据源不存在','data'=>null],404);
            if(!Db::name('organization_nodes')->where('id',$targetId)->where('site_id',$siteId)->where('tenant_id',(int)$session['tenant_id'])->whereNull('deleted_at')->count())return json(['code'=>404,'message'=>'写入目标组织不存在','data'=>null],404);
            $now=date('Y-m-d H:i:s');$queuedId=(int)Db::name('agent_import_batches')->insertGetId(['tenant_id'=>(int)$session['tenant_id'],'site_id'=>$siteId,'profile_id'=>$profileId,'target_organization_id'=>$targetId,'from_date'=>$from,'to_date'=>$to,'types'=>json_encode($payload['types']??['reports','ledger','orders','results'],JSON_UNESCAPED_UNICODE),'status'=>'queued','external_counts'=>null,'created_counts'=>null,'created_credentials'=>null,'started_at'=>null,'finished_at'=>null,'created_at'=>$now,'updated_at'=>$now]);
            $payload['_queued_batch_id']=$queuedId;
            $bodyFile=tempnam(sys_get_temp_dir(),'agent-import-job-');
            if($bodyFile===false) throw new \RuntimeException('后台任务临时文件不可用');
            file_put_contents($bodyFile,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            // Always run the detached worker through the local API listener.
            // The public vhost may resolve to a different proxy (or be
            // unreachable from the server), which leaves batches permanently
            // queued. Keep the public Host header for route/vhost selection.
            $workerUrl='http://127.0.0.1:18082/api/v1/admin/agent-import/batches';
            $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
            $cmd='nohup curl --silent --show-error --max-time 900 -X POST '.escapeshellarg($workerUrl).' -H '.escapeshellarg('Host: kpsadmin.tzgpt.top').' -H '.escapeshellarg('Authorization: Bearer '.$token).' -H '.escapeshellarg('Content-Type: application/json').' -H '.escapeshellarg('X-Agent-Import-Worker: 1').' --data-binary @'.escapeshellarg($bodyFile).' >/dev/null 2>&1; rm -f '.escapeshellarg($bodyFile).' >/dev/null 2>&1 &';
            exec($cmd);
            return json(['code'=>0,'message'=>'做账任务已进入后台，完成后可在批次列表查看创建结果','data'=>['queued'=>true,'batch_id'=>$queuedId]],202);
        }
        // Resolve the UI date range to the issue-number range before calling
        // the reference report/ledger endpoints.
        $response=(new AgentImport())->createBatchResolved($request);
        $result=$this->payload($response);
        if((int)($result['code']??0)!==0){$queuedId=(int)$request->post('_queued_batch_id',0);if($queuedId>0)Db::name('agent_import_batches')->where('id',$queuedId)->update(['status'=>'failed','error'=>mb_substr((string)($result['message']??'后台做账失败'),0,2000),'finished_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);return $response;}
        $batchId=(int)($result['data']['id']??0);
        $queuedId=(int)$request->post('_queued_batch_id',0);
        if($queuedId>0&&$batchId>0&&$queuedId!==$batchId){
            $actual=Db::name('agent_import_batches')->where('id',$batchId)->find();
            if($actual){
                Db::name('agent_import_records')->where('batch_id',$batchId)->update(['batch_id'=>$queuedId]);
                Db::name('agent_import_batches')->where('id',$queuedId)->update(['status'=>$actual['status'],'external_counts'=>$actual['external_counts'],'error'=>$actual['error'],'started_at'=>$actual['started_at'],'finished_at'=>$actual['finished_at'],'updated_at'=>date('Y-m-d H:i:s')]);
                Db::name('agent_import_batches')->where('id',$batchId)->delete();
                $batchId=$queuedId;$result['data']['id']=$queuedId;
            }
        }
        $batch=$batchId?Db::name('agent_import_batches')->where('id',$batchId)->where('tenant_id',(int)$session['tenant_id'])->find():null;
        if(!$batch||$batch['status']!=='completed') return $response;

        $rows=$this->accountRows($batchId);
        if(!$rows)$rows=$this->retryAccounts($batch);
        try {
            $materialized=AgentImportAccountSync::import($batchId,(int)$batch['tenant_id'],(int)$batch['site_id'],(int)$batch['target_organization_id'],$rows);
            $createdCounts=$materialized['stats'];
            Db::name('agent_import_batches')->where('id',$batchId)->update(['created_counts'=>json_encode($createdCounts,JSON_UNESCAPED_UNICODE),'created_credentials'=>json_encode($materialized['credentials'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'updated_at'=>date('Y-m-d H:i:s')]);
            $result['data']['created_counts']=$createdCounts;
            $result['data']['credentials']=$materialized['credentials'];
            $result['message']='做账完成，已创建本地代理/会员账号';
            return json($result);
        } catch(\Throwable $e) {
            Db::name('agent_import_batches')->where('id',$batchId)->update(['status'=>'failed','error'=>mb_substr('账号导入失败：'.$e->getMessage(),0,2000),'updated_at'=>date('Y-m-d H:i:s')]);
            return json(['code'=>502,'message'=>'数据已抓取，但本地账号导入失败：'.$e->getMessage(),'data'=>['id'=>$batchId]],502);
        }
    }

    public function credentials(Request $request, int $id): \think\response\Json
    {
        $session=$this->session($request);
        $batch=Db::name('agent_import_batches')->where('id',$id)->where('tenant_id',(int)$session['tenant_id'])->find();
        if(!$batch)return json(['code'=>404,'message'=>'批次不存在','data'=>null],404);
        $credentials=json_decode((string)($batch['created_credentials']??''),true);
        $credentials=is_array($credentials)?$credentials:[];

        // A later batch may reuse accounts created by an earlier import, so it
        // has no newly-generated password of its own. Recover the saved
        // plaintext from the most recent batch that created the same username.
        $known=[]; foreach($credentials as $item){$u=trim((string)($item['username']??''));if($u!=='')$known[$u]=true;}
        $reusedUsers=[];
        $reuseRecords=Db::name('agent_import_records')->where('batch_id',$id)->where('entity_type','account')->where('action','reused')->select()->toArray();
        foreach($reuseRecords as $record){$payload=json_decode((string)($record['payload']??''),true);$u=trim((string)($payload['username']??($payload['source']['an']??'')));if($u!==''&&!isset($known[$u]))$reusedUsers[$u]=true;}
        // Older completed batches skipped the per-account "reused" audit row.
        // In that case derive usernames from the saved accounts snapshot.
        if($reusedUsers===[]){
            $snapshot=Db::name('agent_import_records')->where('batch_id',$id)->where('entity_type','accounts')->order('id desc')->find();
            $body=$snapshot?json_decode((string)$snapshot['payload'],true):[];
            $data=is_array($body)?($body['response']['data']??[]):[];
            $rows=is_array($data)?(array)($data['al']??$data['list']??$data):[];
            foreach($rows as $row){$u=trim((string)(is_array($row)?($row['an']??$row['username']??''):''));if($u!==''&&!isset($known[$u]))$reusedUsers[$u]=true;}
        }
        if($reusedUsers!==[]){
            $reusedTotal=count($reusedUsers); $reusedFound=0;
            $history=Db::name('agent_import_batches')->where('tenant_id',(int)$session['tenant_id'])->where('site_id',(int)$batch['site_id'])->where('id','<>',$id)->whereNotNull('created_credentials')->order('id desc')->select()->toArray();
            foreach($history as $old){$items=json_decode((string)$old['created_credentials'],true);if(!is_array($items))continue;foreach($items as $item){$u=trim((string)($item['username']??''));if($u!==''&&isset($reusedUsers[$u])&&!isset($known[$u])){$item['reused_from_batch_id']=(int)$old['id'];$credentials[]=$item;$known[$u]=true;$reusedFound++;}}if($reusedFound>=$reusedTotal)break;}
        }
        return json(['code'=>0,'message'=>'ok','data'=>['batch_id'=>$id,'credentials'=>array_values($credentials)]]);
    }

    public function rollback(Request $request): \think\response\Json
    {
        $session=$this->session($request);$batchId=(int)$request->post('batch_id');
        $batch=Db::name('agent_import_batches')->where('id',$batchId)->where('tenant_id',(int)$session['tenant_id'])->find();
        if(!$batch)return json(['code'=>404,'message'=>'批次不存在','data'=>null],404);
        if((string)$batch['status']!=='completed')return json(['code'=>422,'message'=>'只有已完成批次可以回滚','data'=>null],422);
        $removed=AgentImportAccountSync::rollback($batchId,(int)$session['tenant_id']);
        Db::name('agent_import_batches')->where('id',$batchId)->update(['status'=>'rolled_back','updated_at'=>date('Y-m-d H:i:s')]);
        return json(['code'=>0,'message'=>'批次及本地账号已回滚','data'=>['batch_id'=>$batchId,'removed'=>$removed]]);
    }
}
