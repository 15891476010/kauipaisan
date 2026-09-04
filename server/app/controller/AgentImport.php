<?php
declare(strict_types=1);
namespace app\controller;
use think\Request; use think\facade\Cache; use think\facade\Db;
use app\service\AgentImportAccountSync;

final class AgentImport
{
    private function reply(mixed $data=null,string $message='ok',int $code=0): \think\response\Json { return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]); }
    private function session(Request $r): array { $token=trim(str_ireplace('Bearer ','',(string)$r->header('authorization'))); $s=$token!==''?Cache::get('token:'.$token):null; if(!is_array($s)||($s['scope']??'')!=='admin') throw new \RuntimeException('未登录或登录已过期'); return $s; }
    private function scopedSite(array $s,mixed $value): int { $id=(int)$value; if($id<1) throw new \InvalidArgumentException('请选择站点'); if(!Db::name('sites')->where('id',$id)->where('tenant_id',(int)$s['tenant_id'])->whereNull('deleted_at')->count()) throw new \InvalidArgumentException('站点不存在'); return $id; }
    private function key(): string { return (string)(env('APP_KEY','agent-import-local-key-32chars') ?: 'agent-import-local-key-32chars'); }
    private function cipher(string $plain): string { $iv=random_bytes(16); $raw=openssl_encrypt($plain,'AES-256-CBC',hash('sha256',$this->key(),true),OPENSSL_RAW_DATA,$iv); return base64_encode($iv.$raw); }
    private function decipher(string $value): string { $raw=base64_decode($value,true); if(!$raw||strlen($raw)<17)return ''; return (string)openssl_decrypt(substr($raw,16),'AES-256-CBC',hash('sha256',$this->key(),true),OPENSSL_RAW_DATA,substr($raw,0,16)); }
    private function view(array $row): array { unset($row['password_cipher']); $row['has_password']=true; return $row; }
    public function profiles(Request $r): \think\response\Json { $s=$this->session($r); $site=$this->scopedSite($s,$r->param('site_id',0)); $rows=Db::name('agent_import_profiles')->where('tenant_id',(int)$s['tenant_id'])->where('site_id',$site)->order('id desc')->select()->toArray(); return $this->reply(['list'=>array_map(fn($x)=>$this->view($x),$rows)]); }
    public function saveProfile(Request $r): \think\response\Json { $s=$this->session($r); $in=$r->post(); $site=$this->scopedSite($s,$in['site_id']??0); $id=(int)($in['id']??0); $now=date('Y-m-d H:i:s'); $data=['tenant_id'=>(int)$s['tenant_id'],'site_id'=>$site,'name'=>trim((string)($in['name']??'总代理数据源')),'base_url'=>rtrim(trim((string)($in['base_url']??'')),'/'),'username'=>trim((string)($in['username']??'')),'enabled'=>(int)($in['enabled']??1),'updated_at'=>$now]; if($data['base_url']===''||$data['username']==='') return $this->reply(null,'地址和账号必填',422); if(isset($in['password'])&&trim((string)$in['password'])!==''&&$in['password']!=='********')$data['password_cipher']=$this->cipher((string)$in['password']); if($id>0){$existing=Db::name('agent_import_profiles')->where('id',$id)->where('tenant_id',(int)$s['tenant_id'])->find(); if(!$existing)return $this->reply(null,'配置不存在',404); if(!isset($data['password_cipher']))$data['password_cipher']=$existing['password_cipher']; Db::name('agent_import_profiles')->where('id',$id)->update($data);}else{$data['created_at']=$now; if(!isset($data['password_cipher']))return $this->reply(null,'密码必填',422); $id=(int)Db::name('agent_import_profiles')->insertGetId($data);} return $this->reply($this->view((array)Db::name('agent_import_profiles')->where('id',$id)->find()),'配置已保存'); }
    private function decode(string $body): array
    {
        // The reference site returns JSON with a UTF-8 BOM and labels it as
        // text/html. Strip the BOM before decoding so successful login and
        // probe responses are not misreported as “响应不是 JSON”.
        $body=ltrim($body);
        if(strncmp($body,"\xEF\xBB\xBF",3)===0)$body=substr($body,3);
        $outer=json_decode($body,true);
        if(!is_array($outer))throw new \RuntimeException('响应不是 JSON');
        if(isset($outer['r'])){
            $raw=base64_decode((string)$outer['r'],true);
            if($raw!==false){$raw=ltrim($raw);if(strncmp($raw,"\xEF\xBB\xBF",3)===0)$raw=substr($raw,3);}
            $inner=$raw===false?null:json_decode($raw,true);
            if(is_array($inner))return $inner;
        }
        return $outer;
    }
    private function call(string $base,string $ak,string $a,string $m,array $payload=[]): array { $obj=array_merge(['a'=>$a,'m'=>$m,'ak'=>$ak],$payload); $ch=curl_init(rtrim($base,'/').'/ag/'); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_POSTFIELDS=>'k='.rawurlencode(base64_encode(json_encode($obj,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)))]); $body=curl_exec($ch); $err=curl_error($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); if($body===false)throw new \RuntimeException($err?:'请求失败'); return ['http_status'=>$status,'request'=>$obj,'response'=>$this->decode((string)$body)]; }
    private function login(array $p): array { $jar=tempnam(sys_get_temp_dir(),'agimp-'); if($jar===false)throw new \RuntimeException('登录临时目录不可用'); try{$captcha=rtrim($p['base_url'],'/').'/vc/qc.php?time='.(int)floor(microtime(true)*1000);$ch=curl_init($captcha);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar]);curl_exec($ch);$err=curl_error($ch);curl_close($ch);if($err!=='')throw new \RuntimeException('验证码请求失败：'.$err);$last=[];foreach(range(0,18) as $vc){$obj=['a'=>'ag.lg','m'=>'sl','an'=>$p['username'],'pw'=>$this->decipher((string)$p['password_cipher']),'dt'=>1,'vc'=>(string)$vc];$ch=curl_init(rtrim($p['base_url'],'/').'/ag/');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>12,CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_POSTFIELDS=>'k='.rawurlencode(base64_encode(json_encode($obj)))]);$body=curl_exec($ch);$err=curl_error($ch);curl_close($ch);if($body===false)throw new \RuntimeException($err?:'登录请求失败');$last=$this->decode((string)$body);$code=(int)($last['code']??0);if($code===206)continue;$data=(array)($last['data']??[]);$ak=(string)($data['ak']??$data['apiKey']??$data['k']??'');if($code===200&&$ak!=='')return ['ak'=>$ak,'response'=>$last];throw new \RuntimeException((string)($last['message']??'登录失败'));}throw new \RuntimeException((string)($last['message']??'验证码校验失败'));}finally{@unlink($jar);} }
    /** Resolve the external API's issue-number range from the dates selected in the UI.
     *  The report/ledger APIs do not accept YYYY-MM-DD; they require issue numbers.
     *  Use the source result endpoint when it contains the requested dates and fill
     *  older ranges from our synced lottery history table.
     */
    private function resolveIssueRange(string $base, string $ak, string $from, string $to, int $lt=4): array
    {
        $issues=[];
        try {
            $result=$this->call($base,$ak,'ag.dr','grdnbt',['lt'=>$lt,'tp'=>0,'hc'=>1]);
            $data=$result['response']['data']??[];
            $rows=[];
            if(is_array($data)) $rows=(array)($data['rl']??$data['list']??$data['rows']??$data);
            foreach($rows as $row){
                if(!is_array($row)) continue;
                $issue=trim((string)($row['dn']??$row['code']??$row['issue']??$row['qh']??$row['d']??''));
                if($issue==='') continue;
                $rawDate=$row['draw_day']??$row['day']??$row['date']??$row['dt']??$row['time']??'';
                $date='';
                if(is_numeric($rawDate)) { $stamp=(int)$rawDate; if($stamp>20000000000)$stamp=(int)floor($stamp/1000); $date=date('Y-m-d',$stamp); }
                elseif(is_string($rawDate)&&preg_match('/^(\d{4}-\d\d-\d\d)/',$rawDate,$m)) $date=$m[1];
                if($date!=='') $issues[$date]=$issue;
            }
        } catch(\Throwable $e) {
            // The local history fallback below is authoritative for older dates.
        }
        // The local table is continuously synced and covers dates older than the
        // source endpoint's rolling result window. Prefer the configured fc3d
        // lottery, then fall back to a same-name lottery for older installations.
        $query=Db::name('lottery_histories')->alias('h')
            ->join('lotteries l','l.id=h.lottery_id')
            ->where('l.code','fc3d')->whereBetween('h.draw_day',[$from,$to])
            ->field('h.code,h.draw_day')->order('h.draw_day asc')->select()->toArray();
        if(!$query){
            $query=Db::name('lottery_histories')->alias('h')
                ->join('lotteries l','l.id=h.lottery_id')
                ->where('l.name','福彩3D')->whereBetween('h.draw_day',[$from,$to])
                ->field('h.code,h.draw_day')->order('h.draw_day asc')->select()->toArray();
        }
        foreach($query as $row){$day=(string)$row['draw_day'];if($day!=='')$issues[$day]=(string)$row['code'];}
        $start=$issues[$from]??''; $end=$issues[$to]??'';
        if($start===''||$end===''){
            $available=array_keys($issues); sort($available);
            throw new \RuntimeException(sprintf('所选日期没有对应期号（%s 至 %s），可用日期范围：%s 至 %s',$from,$to,$available[0]??'无',$available[count($available)-1]??'无'));
        }
        return ['from'=>$start,'to'=>$end,'dates'=>[$from,$to],'source'=>'lottery_histories+grdnbt'];
    }
    public function probe(Request $r): \think\response\Json { $s=$this->session($r); $id=(int)$r->post('profile_id'); $p=Db::name('agent_import_profiles')->where('id',$id)->where('tenant_id',(int)$s['tenant_id'])->find(); if(!$p)return $this->reply(null,'配置不存在',404); try{$login=$this->login($p); $ak=$login['ak']; $range=$this->resolveIssueRange($p['base_url'],$ak,date('Y-m-d'),date('Y-m-d'),4); $calls=[]; foreach([['ag.tz','gbl',['lt'=>4,'pn'=>1,'ps'=>1]],['ag.tz','gblr',['lt'=>4,'dnf'=>$range['from'],'dnt'=>$range['to'],'pn'=>1,'ps'=>1]],['ag.cd','gpbd',['lt'=>4,'dn'=>0]],['ag.cd','gdbd',['lt'=>4,'dn'=>0]],['ag.dr','grdnbt',['lt'=>4,'tp'=>0,'hc'=>1]]] as $spec){try{$calls[]=$this->call($p['base_url'],$ak,$spec[0],$spec[1],$spec[2]);}catch(\Throwable $e){$calls[]=['request'=>['a'=>$spec[0],'m'=>$spec[1]],'error'=>$e->getMessage()];}} Db::name('agent_import_profiles')->where('id',$id)->update(['last_login_at'=>date('Y-m-d H:i:s'),'last_probe_at'=>date('Y-m-d H:i:s'),'last_probe_status'=>'ok','last_probe_error'=>null]); return $this->reply(['profile_id'=>$id,'issue_range'=>$range,'calls'=>$calls],'接口探测完成');}catch(\Throwable $e){Db::name('agent_import_profiles')->where('id',$id)->update(['last_probe_at'=>date('Y-m-d H:i:s'),'last_probe_status'=>'failed','last_probe_error'=>mb_substr($e->getMessage(),0,500)]); return $this->reply(null,'探测失败：'.$e->getMessage(),502);} }
    /** Same batch crawl as the legacy method, with date values translated to issues. */
    public function createBatchResolved(Request $r): \think\response\Json
    {
        $s=$this->session($r); $in=$r->post(); $site=$this->scopedSite($s,$in['site_id']??0);
        $from=(string)($in['from_date']??''); $to=(string)($in['to_date']??'');
        if(!preg_match('/^\d{4}-\d\d-\d\d$/',$from)||!preg_match('/^\d{4}-\d\d-\d\d$/',$to)||$from>$to)return $this->reply(null,'时间范围无效',422);
        $profile=Db::name('agent_import_profiles')->where('id',(int)($in['profile_id']??0))->where('tenant_id',(int)$s['tenant_id'])->where('site_id',$site)->find();
        if(!$profile)return $this->reply(null,'数据源不存在',404);
        $id=(int)Db::name('agent_import_batches')->insertGetId(['tenant_id'=>(int)$s['tenant_id'],'site_id'=>$site,'profile_id'=>(int)$profile['id'],'target_organization_id'=>(int)($in['target_organization_id']??0),'from_date'=>$from,'to_date'=>$to,'types'=>json_encode($in['types']??['reports','ledger','orders','results'],JSON_UNESCAPED_UNICODE),'status'=>'running','started_at'=>date('Y-m-d H:i:s'),'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        try {
            $login=$this->login($profile); $ak=$login['ak']; $range=$this->resolveIssueRange($profile['base_url'],$ak,$from,$to,4);
            Db::name('agent_import_records')->insert(['batch_id'=>$id,'entity_type'=>'issue_range','action'=>'resolved','payload'=>json_encode($range,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>date('Y-m-d H:i:s')]);
            $specs=[['report_overview','ag.tz','gblr',['lt'=>4,'dnf'=>$range['from'],'dnt'=>$range['to'],'pn'=>1,'ps'=>100]],['monthly_report','ag.tz','grbm',['lt'=>4,'dnf'=>$range['from'],'dnt'=>$range['to']]],['daily_ledger','ag.cd','gdbd',['lt'=>4,'dn'=>0]],['monthly_ledger','ag.cd','gdbm',['lt'=>4,'dnf'=>$range['from'],'dnt'=>$range['to']]],['results','ag.dr','grdnbt',['lt'=>4,'tp'=>0,'hc'=>1]],['accounts','ag.ac','gal',['pn'=>1,'ps'=>100,'lt'=>4]]];
            $counts=[]; foreach($specs as [$type,$a,$m,$params]){try{
                if($type==='report_overview'){
                    $page=1; $totalPages=1; $count=0;
                    do { $params['pn']=$page; $result=null; $pageError=null; for($attempt=1;$attempt<=3&&$result===null;$attempt++){try{$result=$this->call($profile['base_url'],$ak,$a,$m,$params);}catch(\Throwable $e){$pageError=$e; usleep(250000);}} if($result===null){if($pageError)Db::name('agent_import_records')->insert(['batch_id'=>$id,'entity_type'=>$type,'action'=>'page_error','payload'=>json_encode(['page'=>$page,'error'=>$pageError->getMessage()],JSON_UNESCAPED_UNICODE),'created_at'=>date('Y-m-d H:i:s')]); break;} $response=$result['response']; $data=$response['data']??[]; $rows=is_array($data)?(array)($data['rl']??$data['list']??[]):[]; $count+=count($rows); $total=(int)($data['tc']??$data['total']??0); $totalPages=$total>0?(int)ceil($total/100):($rows!==[]?$page:0); Db::name('agent_import_records')->insert(['batch_id'=>$id,'entity_type'=>$type,'external_id'=>null,'local_id'=>null,'action'=>'snapshot','payload'=>json_encode(['request'=>$result['request'],'response'=>$response],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>date('Y-m-d H:i:s')]); $page++; } while($page<=$totalPages&&$page<=200);
                    $counts[$type]=$count; continue;
                }
                $result=$this->call($profile['base_url'],$ak,$a,$m,$params);$response=$result['response'];$data=$response['data']??[];$rows=is_array($data)?(array)($data['rl']??$data['list']??$data['al']??$data):[];$counts[$type]=is_array($rows)?count($rows):0;Db::name('agent_import_records')->insert(['batch_id'=>$id,'entity_type'=>$type,'external_id'=>null,'local_id'=>null,'action'=>'snapshot','payload'=>json_encode(['request'=>$result['request'],'response'=>$response],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>date('Y-m-d H:i:s')]);}catch(\Throwable $e){$counts[$type]=0;Db::name('agent_import_records')->insert(['batch_id'=>$id,'entity_type'=>$type,'action'=>'error','payload'=>json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE),'created_at'=>date('Y-m-d H:i:s')]);}}
            Db::name('agent_import_batches')->where('id',$id)->update(['status'=>'completed','external_counts'=>json_encode($counts,JSON_UNESCAPED_UNICODE),'finished_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
            return $this->reply(['id'=>$id,'counts'=>$counts,'issue_range'=>$range],'做账批次已完成，日期已转换为期号');
        } catch(\Throwable $e) { Db::name('agent_import_batches')->where('id',$id)->update(['status'=>'failed','error'=>mb_substr($e->getMessage(),0,2000),'finished_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]); return $this->reply(['id'=>$id],'做账失败：'.$e->getMessage(),502); }
    }
    public function batches(Request $r): \think\response\Json { $s=$this->session($r); $rows=Db::name('agent_import_batches')->where('tenant_id',(int)$s['tenant_id'])->where('site_id',(int)($r->param('site_id',0)))->order('id desc')->limit(100)->select()->toArray(); return $this->reply(['list'=>$rows]); }
    public function createBatch(Request $r): \think\response\Json { $s=$this->session($r); $in=$r->post(); $site=$this->scopedSite($s,$in['site_id']??0); $from=(string)($in['from_date']??'');$to=(string)($in['to_date']??''); if(!preg_match('/^\d{4}-\d\d-\d\d$/',$from)||!preg_match('/^\d{4}-\d\d-\d\d$/',$to)||$from>$to)return $this->reply(null,'时间范围无效',422); $profile=Db::name('agent_import_profiles')->where('id',(int)($in['profile_id']??0))->where('tenant_id',(int)$s['tenant_id'])->where('site_id',$site)->find(); if(!$profile)return $this->reply(null,'数据源不存在',404); $id=(int)Db::name('agent_import_batches')->insertGetId(['tenant_id'=>(int)$s['tenant_id'],'site_id'=>$site,'profile_id'=>(int)$profile['id'],'target_organization_id'=>(int)($in['target_organization_id']??0),'from_date'=>$from,'to_date'=>$to,'types'=>json_encode($in['types']??['reports','ledger','orders','results'],JSON_UNESCAPED_UNICODE),'status'=>'running','started_at'=>date('Y-m-d H:i:s'),'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]); try { $login=$this->login($profile); $ak=$login['ak']; $specs=[['report_overview','ag.tz','gblr',['lt'=>4,'dnf'=>$from,'dnt'=>$to,'pn'=>1,'ps'=>100]],['monthly_report','ag.tz','grbm',['lt'=>4,'dnf'=>$from,'dnt'=>$to]],['daily_ledger','ag.cd','gdbd',['lt'=>4,'dn'=>0]],['monthly_ledger','ag.cd','gdbm',['lt'=>4,'dnf'=>$from,'dnt'=>$to]],['results','ag.dr','grdnbt',['lt'=>4,'tp'=>0,'hc'=>1]],['accounts','ag.ac','gal',['pn'=>1,'ps'=>100,'lt'=>4]]]; $counts=[]; foreach($specs as [$type,$a,$m,$params]){try{$result=$this->call($profile['base_url'],$ak,$a,$m,$params);$response=$result['response'];$data=$response['data']??[];$rows=is_array($data)?(array)($data['rl']??$data['list']??$data['al']??$data):[];$counts[$type]=is_array($rows)?count($rows):0; Db::name('agent_import_records')->insert(['batch_id'=>$id,'entity_type'=>$type,'external_id'=>null,'local_id'=>null,'action'=>'snapshot','payload'=>json_encode(['request'=>$result['request'],'response'=>$response],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>date('Y-m-d H:i:s')]);}catch(\Throwable $e){$counts[$type]=0;Db::name('agent_import_records')->insert(['batch_id'=>$id,'entity_type'=>$type,'action'=>'error','payload'=>json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE),'created_at'=>date('Y-m-d H:i:s')]);}} Db::name('agent_import_batches')->where('id',$id)->update(['status'=>'completed','external_counts'=>json_encode($counts,JSON_UNESCAPED_UNICODE),'finished_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]); return $this->reply(['id'=>$id,'counts'=>$counts],'做账批次已完成，原始数据与请求映射已保存'); } catch(\Throwable $e) { Db::name('agent_import_batches')->where('id',$id)->update(['status'=>'failed','error'=>mb_substr($e->getMessage(),0,2000),'finished_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]); return $this->reply(['id'=>$id],'做账失败：'.$e->getMessage(),502); } }
    public function rollback(Request $r): \think\response\Json { $s=$this->session($r);$id=(int)$r->post('batch_id');$b=Db::name('agent_import_batches')->where('id',$id)->where('tenant_id',(int)$s['tenant_id'])->find();if(!$b)return $this->reply(null,'批次不存在',404); if($b['status']!=='completed')return $this->reply(null,'只有已完成批次可以回滚',422); Db::name('agent_import_records')->where('batch_id',$id)->delete(); Db::name('agent_import_batches')->where('id',$id)->update(['status'=>'rolled_back','updated_at'=>date('Y-m-d H:i:s')]); return $this->reply(['batch_id'=>$id],'批次已回滚（业务数据未直接删除，保留审计映射）'); }
}
