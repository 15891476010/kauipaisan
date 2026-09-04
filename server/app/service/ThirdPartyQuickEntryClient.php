<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Cache;

/**
 * Isolated client for the reference quick-entry API. It does not parse or
 * alter local betting rules; callers may use its decoded result alongside the
 * existing QuickEntryParser.
 */
final class ThirdPartyQuickEntryClient
{
    private const CACHE_PREFIX = 'quick-entry:third-party:';
    private const STATS_TTL = 7776000;
    private const RECOGNIZE_TIMEOUT_MS = 1200;
    private const MAX_RECOGNIZE_ROUNDS = 2;
    private const MAX_RECOGNIZE_WALL_MS = 5000;
    private const HEALTH_WORKER_LOCK_TTL = 180;

    public function __construct(private readonly array $config, private readonly ?ThirdPartyCaptchaRecognizer $captcha = null)
    {
        if (trim((string)($config['base_url'] ?? '')) === '') throw new \InvalidArgumentException('三方平台 Base URL 未配置');
    }

    /** @return array<string,mixed> */
    public function recognize(string $text, int $dlt): array
    {
        $accounts = array_values(array_filter((array)($this->config['accounts'] ?? []), static fn($a): bool => is_array($a) && trim((string)($a['username'] ?? '')) !== '' && (string)($a['password'] ?? '') !== ''));
        if ($accounts === []) throw new \RuntimeException('三方账号池为空');
        // Reuse authenticated accounts first and rotate the starting position
        // so concurrent users do not all hammer the first account.
        usort($accounts, function(array $left, array $right): int {
            $leftKey=$this->accountKey($left);$rightKey=$this->accountKey($right);
            $leftToken=$this->cachedToken($leftKey)!==null?0:1;$rightToken=$this->cachedToken($rightKey)!==null?0:1;
            if($leftToken!==$rightToken)return $leftToken<=>$rightToken;
            $leftStats=$this->stats($leftKey);$rightStats=$this->stats($rightKey);
            return (float)($leftStats['last_used_microtime']??0)<=>(float)($rightStats['last_used_microtime']??0);
        });
        $offset=$this->nextAccountOffset(count($accounts));
        if($offset>0)$accounts=array_merge(array_slice($accounts,$offset),array_slice($accounts,0,$offset));
        $lastReasons=[];$hadToken=false;$requestStarted=microtime(true);
        // Try one account at a time. The provider applies a per-account and
        // sometimes per-IP request interval; fanning out the whole pool at
        // once causes its anti-flood gate to delay every request. Rotation is
        // still fast because each attempt is capped at 1.2s and the API now
        // runs with multiple workers.
        for($round=0;$round<self::MAX_RECOGNIZE_ROUNDS;$round++) {
            foreach($accounts as $account){
                if ((microtime(true)-$requestStarted)*1000 >= self::MAX_RECOGNIZE_WALL_MS) { $lastReasons[]='本次识别达到总时限'; break 2; }
                $accountKey=$this->accountKey($account);$token=$this->cachedToken($accountKey);
                if($token===null){
                    // Login is deliberately kept out of the user request.
                    // The health worker refreshes expired accounts.
                    $this->scheduleBackgroundHealthCheck();$lastReasons[]=(string)$account['username'].'：AK 不在线，已转后台登录';continue;
                }
                $hadToken=true;$wait=$this->throttleWait($accountKey,$account);
                if($wait>0){$lastReasons[]=(string)$account['username'].'：请求频率受限';continue;}
                $started=microtime(true);$this->markAttempt($accountKey,$account,$token);
                try{$result=$this->recognizeWithToken($token,$text,$dlt,self::RECOGNIZE_TIMEOUT_MS);}
                catch(\Throwable $e){$this->markFailure($accountKey,$account,$e->getMessage(),$started);$lastReasons[]=(string)$account['username'].'：'.$e->getMessage();continue;}
                if(ThirdPartyQuickEntryUtils::tokenRejected($result)){
                    $this->forgetToken($accountKey);$this->markFailure($accountKey,$account,'token_rejected',$started);
                    $this->scheduleBackgroundHealthCheck();$lastReasons[]=(string)$account['username'].'：AK 已失效，已转后台登录';continue;
                }
                if(ThirdPartyQuickEntryUtils::rateLimited($result)){$this->markFailure($accountKey,$account,'三方识别请求频率受限',$started);$lastReasons[]=$account['username'].'：三方识别请求频率受限';continue;}
                $this->markCall($accountKey,$account);$this->markSuccess($accountKey,$account,$token,$started);$result['_account']=['id'=>(string)($account['id']??''),'username'=>(string)$account['username'],'ak'=>$token];return $result;
            }
            if(!$hadToken){$this->scheduleBackgroundHealthCheck();}
        }
        $reason='三方识别服务暂时繁忙，请稍后重试';
        if($lastReasons!==[]){
            $details=implode('；',array_values(array_unique(array_map('strval',$lastReasons))));
            // Keep account/AK rotation diagnostics server-side. The user
            // only needs an actionable recognition prompt, never credentials,
            // token state, account names, or upstream timeout details.
            error_log('third-party quick recognition exhausted: '.mb_substr($details,0,500));
        }
        // Return a provider-shaped failure instead of throwing after all
        // accounts are exhausted. Existing user preview code can therefore
        // render the precise reason and never fall back to a misleading local
        // parse or leave the page waiting on a synchronous login.
        $visible='识别服务暂时不可用，请点击“生成”重试';
        return ['code'=>503,'message'=>$visible,'data'=>['rl'=>[['isSuccess'=>0,'txt'=>$visible,'message'=>$visible,'reason'=>$visible]],'ta'=>0,'tc'=>0]];
    }

    /** @return array{accounts:array<int,array<string,mixed>>,current_account:?array<string,mixed>} */
    public function poolStatus(): array
    {
        $current=Cache::get(self::CACHE_PREFIX.'current-account');$rows=[];$currentRow=null;
        foreach((array)($this->config['accounts']??[]) as $account){
            if(!is_array($account)||trim((string)($account['username']??''))==='')continue;
            $key=$this->accountKey($account);$stats=$this->stats($key);$token=$this->tokenInfo($key);$throttle=$this->throttleState($key,$account);
            $isCurrent=is_array($current)&&hash_equals((string)($current['account_key']??''),$key);
            $row=['id'=>(string)($account['id']??''),'username'=>(string)$account['username'],'is_current'=>$isCurrent,
                'call_count'=>(int)($stats['call_count']??0),'success_count'=>(int)($stats['success_count']??0),'failure_count'=>(int)($stats['failure_count']??0),
                'login_count'=>(int)($stats['login_count']??0),'login_failure_count'=>(int)($stats['login_failure_count']??0),
                'health_check_count'=>(int)($stats['health_check_count']??0),'last_health_at'=>(string)($stats['last_health_at']??''),
                'last_health_status'=>(string)($stats['last_health_status']??''),'last_health_error'=>(string)($stats['last_health_error']??''),
                'window_call_count'=>(int)$throttle['count'],'frozen_until'=>$this->formatTime((float)$throttle['frozen_until']),
                'ak'=>(string)($token['ak']??''),'ak_expires_at'=>$this->formatTime((float)($token['expires_at']??0)),
                'last_used_at'=>(string)($stats['last_used_at']??''),'last_duration_ms'=>(int)($stats['last_duration_ms']??0),
                'last_status'=>(string)($stats['last_status']??''),'last_error'=>(string)($stats['last_error']??'')];
            $rows[]=$row;if($isCurrent)$currentRow=$row;
        }
        return ['accounts'=>$rows,'current_account'=>$currentRow];
    }

    /**
     * Send one lightweight recognition request with a selected account's AK.
     * A new login is performed only when there is no usable cached AK or the
     * provider explicitly rejects it. Transport/parser errors never trigger a
     * login because they do not prove that the AK is invalid.
     *
     * @return array<string,mixed>
     */
    public function healthCheckAccount(string $accountId): array
    {
        foreach((array)($this->config['accounts']??[]) as $account){
            if(!is_array($account)||(string)($account['id']??'')!==$accountId)continue;
            if(trim((string)($account['username']??''))===''||(string)($account['password']??'')==='')throw new \RuntimeException('账号或密码未配置完整');
            $accountKey=$this->accountKey($account);$started=microtime(true);$token=$this->cachedToken($accountKey);$this->markHealthAttempt($accountKey);
            if($token===null){
                try{
                    $login=$this->loginAccount($accountId);
                    $this->markHealth($accountKey,'relogged','缓存中没有有效 AK，已自动登录',$started);
                    return ['id'=>$accountId,'username'=>(string)$account['username'],'status'=>'relogged','reason'=>'missing_or_expired','ak'=>(string)$login['ak'],'duration_ms'=>(int)round((microtime(true)-$started)*1000)];
                }catch(\Throwable $error){
                    $this->markHealth($accountKey,'failed',$error->getMessage(),$started);
                    throw $error;
                }
            }
            try{
                $result=$this->recognizeWithToken($token,'123直1元',4);
                if(ThirdPartyQuickEntryUtils::tokenRejected($result)){
                    $this->forgetToken($accountKey);
                    $login=$this->loginAccount($accountId);
                    $this->markHealth($accountKey,'relogged','AK 已失效，已自动登录',$started);
                    return ['id'=>$accountId,'username'=>(string)$account['username'],'status'=>'relogged','reason'=>'token_rejected','ak'=>(string)$login['ak'],'duration_ms'=>(int)round((microtime(true)-$started)*1000)];
                }
                $this->markCall($accountKey,$account);
                $this->markHealth($accountKey,'valid','AK 有效',$started);
                return ['id'=>$accountId,'username'=>(string)$account['username'],'status'=>'valid','reason'=>'recognized','ak'=>$token,'provider_code'=>ThirdPartyQuickEntryUtils::responseCode($result),'duration_ms'=>(int)round((microtime(true)-$started)*1000)];
            }catch(\Throwable $error){
                $this->markHealth($accountKey,'failed',$error->getMessage(),$started);
                throw $error;
            }
        }
        throw new \InvalidArgumentException('账号池中未找到该账号');
    }

    /** @return array<int,array<string,mixed>> */
    public function healthCheckAllAccounts(): array
    {
        $results=[];
        foreach((array)($this->config['accounts']??[]) as $account){
            if(!is_array($account)||trim((string)($account['username']??''))==='')continue;
            $id=(string)($account['id']??'');
            try{$results[]=$this->healthCheckAccount($id);}
            catch(\Throwable $error){$results[]=['id'=>$id,'username'=>(string)($account['username']??''),'status'=>'failed','error'=>$error->getMessage()];}
        }
        return $results;
    }

    /** Log in one configured account immediately and cache its fresh AK. */
    public function loginAccount(string $accountId): array
    {
        foreach((array)($this->config['accounts']??[]) as $account){
            if(!is_array($account)||(string)($account['id']??'')!==$accountId)continue;
            if(trim((string)($account['username']??''))===''||(string)($account['password']??'')==='')throw new \RuntimeException('账号或密码未配置完整');
            $accountKey=$this->accountKey($account);$allStarted=microtime(true);$lastError=null;
            for($attempt=1;$attempt<=3;$attempt++){
                $started=microtime(true);
                try{
                    // Keep the previous cached AK until the new login succeeds,
                    // so an OCR failure does not interrupt a working account.
                    $token=$this->login($account,$accountKey);$this->markLogin($accountKey,$account,$token,$started);
                    return ['id'=>$accountId,'username'=>(string)$account['username'],'ak'=>$token,
                        'ak_expires_at'=>date('Y-m-d H:i:s',time()+(int)$this->config['token_ttl_seconds']),
                        'duration_ms'=>(int)round((microtime(true)-$allStarted)*1000),'attempts'=>$attempt];
                }catch(\Throwable $e){$this->markLoginFailure($accountKey,$account,$e,$started);$lastError=$e;}
            }
            throw $lastError??new \RuntimeException('账号登录失败');
        }
        throw new \InvalidArgumentException('账号池中未找到该账号');
    }

    /** @return string */
    private function login(array $account, string $accountKey): string
    {
        $cookieJar = $this->cookieJar($accountKey);
        $captchaUrl = $this->url((string)($this->config['captcha_endpoint'] ?? '/vc/qc.php'));
        $captchaUrl .= (str_contains($captchaUrl, '?') ? '&' : '?').'time='.(int)floor(microtime(true) * 1000);
        $captchaResponse = $this->request('GET', $captchaUrl, null, [], $cookieJar);
        if ($captchaResponse['status'] < 200 || $captchaResponse['status'] >= 300 || $captchaResponse['body'] === '') throw new \RuntimeException('获取三方验证码失败');
        $recognizer = $this->captcha ?? new ThirdPartyCaptchaRecognizer();
        $candidates=[];
        try{$candidates[]=$recognizer->recognize($captchaResponse['body'],$this->config);}catch(\Throwable){/* numeric fallback below */}
        // The provider captcha is a single-digit addition expression and
        // keeps the same answer in session after code 206.  If OCR is blurred
        // or returns a plausible but wrong value, try the remaining 0..18
        // answers against the same captcha instead of making the operator
        // click 登录 repeatedly.
        foreach(range(0,18) as $candidate)if(!in_array((string)$candidate,$candidates,true))$candidates[]=(string)$candidate;
        foreach($candidates as $verifyCode){
            $payload = ['a'=>'mb.lg','m'=>'ml','an'=>(string)$account['username'],'pw'=>(string)$account['password'],'dt'=>1,'vc'=>$verifyCode];
            $response = $this->postEnvelope((string)($this->config['login_endpoint'] ?? '/mb/'), $payload, $cookieJar);
            $code=ThirdPartyQuickEntryUtils::responseCode($response);
            if($code===206)continue;
            if($code!==200)throw new \RuntimeException((string)($response['message'] ?? '三方登录失败'));
            $token = trim((string)($response['data']['ak'] ?? $response['data']['token'] ?? $response['ak'] ?? $response['token'] ?? ''));
            if ($token === '') throw new \RuntimeException('三方登录响应缺少 ak');
            Cache::set(self::CACHE_PREFIX.'token:'.$accountKey, ['ak'=>$token,'expires_at'=>time() + (int)$this->config['token_ttl_seconds']], (int)$this->config['token_ttl_seconds']);
            return $token;
        }
        throw new \RuntimeException('三方验证码校验失败');
    }

    /** @return array<string,mixed> */
    private function recognizeWithToken(string $token, string $text, int $dlt, ?int $timeoutMs = null): array
    {
        $bt = ThirdPartyQuickEntryUtils::encodeBetText($text);
        return $this->postEnvelope((string)($this->config['recognize_endpoint'] ?? '/mb/'), ['a'=>'mb.tz','m'=>'dct','ak'=>$token,'dlt'=>$dlt,'bt'=>$bt], null, $timeoutMs);
    }

    /** @return array<string,mixed> */
    private function postEnvelope(string $endpoint, array $payload, ?string $cookieJar = null, ?int $timeoutMs = null): array
    {
        $body = ThirdPartyQuickEntryUtils::encodeEnvelope($payload);
        $response = $this->request('POST', $this->url($endpoint), $body, ['Content-Type: application/x-www-form-urlencoded'], $cookieJar, $timeoutMs);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('三方接口 HTTP 状态异常: '.(int)$response['status']);
        }
        return ThirdPartyQuickEntryUtils::decodeEnvelope($response['body']);
    }

    /** @return array{status:int,body:string} */
    private function request(string $method, string $url, ?string $body, array $headers, ?string $cookieJar = null, ?int $timeoutMs = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) throw new \RuntimeException('无法初始化三方请求');
        $options = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_HTTPHEADER=>$headers];
        if($timeoutMs!==null){$options[CURLOPT_TIMEOUT_MS]=max(100,$timeoutMs);$options[CURLOPT_CONNECTTIMEOUT_MS]=min(1200,max(100,$timeoutMs));}
        else {$options[CURLOPT_TIMEOUT]=(int)$this->config['request_timeout'];$options[CURLOPT_CONNECTTIMEOUT]=5;}
        if ($method === 'POST') { $options[CURLOPT_POST] = true; $options[CURLOPT_POSTFIELDS] = $body ?? ''; }
        if ($cookieJar) { $options[CURLOPT_COOKIEJAR] = $cookieJar; $options[CURLOPT_COOKIEFILE] = $cookieJar; }
        curl_setopt_array($ch, $options); $responseBody = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $error = curl_error($ch); curl_close($ch);
        if ($responseBody === false) throw new \RuntimeException('三方请求失败: '.$error);
        return ['status'=>$status,'body'=>(string)$responseBody];
    }

    private function url(string $endpoint): string
    {
        if (preg_match('#^https?://#i', $endpoint)) return $endpoint;
        return rtrim((string)$this->config['base_url'], '/').'/'.ltrim($endpoint, '/');
    }

    private function accountKey(array $account): string
    {
        return hash('sha256', (string)$this->config['base_url'].'|'.(string)$account['username'].'|'.(string)$account['password']);
    }
    private function nextAccountOffset(int $count): int
    {
        if($count<2)return 0;
        $path=rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'quick-entry-account-cursor-'.hash('sha256',(string)$this->config['base_url']);
        $handle=@fopen($path,'c+');
        if($handle===false)return random_int(0,$count-1);
        $offset=0;
        if(@flock($handle,LOCK_EX)){
            $raw=stream_get_contents($handle);$current=is_string($raw)?(int)trim($raw):0;$offset=$current%$count;
            ftruncate($handle,0);rewind($handle);fwrite($handle,(string)($current+1));fflush($handle);flock($handle,LOCK_UN);
        }
        fclose($handle);return $offset;
    }
    private function cachedToken(string $accountKey): ?string { $value=$this->tokenInfo($accountKey);return $value!==null?(string)$value['ak']:null; }
    private function tokenInfo(string $accountKey): ?array { $value=Cache::get(self::CACHE_PREFIX.'token:'.$accountKey);return is_array($value)&&(int)($value['expires_at']??0)>time()?$value:null; }
    private function forgetToken(string $accountKey): void { Cache::delete(self::CACHE_PREFIX.'token:'.$accountKey); }
    private function cookieJar(string $accountKey): string { return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'quick-entry-'.$accountKey.'.cookie'; }

    private function throttlePolicy(array $account): array
    {
        $window = $account['rate_window_seconds'] ?? ($this->config['rate_window_seconds'] ?? 0);
        $limit = $account['rate_limit_calls'] ?? ($this->config['freeze_after_calls'] ?? 3);
        $freeze = $account['freeze_seconds'] ?? ($this->config['freeze_seconds'] ?? 3);
        return [
            'window' => max(0, min(86400, (int)$window)),
            'limit' => max(1, min(1000, (int)$limit)),
            'freeze' => max(0, min(3600, (int)$freeze)),
        ];
    }

    private function throttleWait(string $accountKey, array $account): float
    {
        $policy = $this->throttlePolicy($account);
        $key = self::CACHE_PREFIX.'throttle:'.hash('sha256', $accountKey);
        $state = Cache::get($key);
        if (!is_array($state)) return 0.0;
        $now = microtime(true);
        $frozen = (float)($state['frozen_until'] ?? 0);
        if ($frozen > $now) return $frozen - $now;
        $timestamps = array_values(array_filter((array)($state['timestamps'] ?? []), static fn($time): bool => is_numeric($time) && $policy['window'] > 0 && (float)$time >= $now - $policy['window']));
        $count = $policy['window'] > 0 ? count($timestamps) : (int)($state['count'] ?? 0);
        if ($count >= $policy['limit']) {
            $until = $now + $policy['freeze'];
            Cache::set($key, ['count'=>0, 'timestamps'=>[], 'frozen_until'=>$until], max(60, $policy['window'] + $policy['freeze'] + 10));
            return $policy['freeze'];
        }
        return 0.0;
    }

    private function markCall(string $accountKey, array $account): void
    {
        $policy = $this->throttlePolicy($account);
        $key = self::CACHE_PREFIX.'throttle:'.hash('sha256', $accountKey);
        $state = Cache::get($key);
        $now = microtime(true);
        $timestamps = array_values(array_filter((array)(is_array($state) ? ($state['timestamps'] ?? []) : []), static fn($time): bool => is_numeric($time) && $policy['window'] > 0 && (float)$time >= $now - $policy['window']));
        $count = $policy['window'] > 0 ? count($timestamps) + 1 : (int)(is_array($state) ? ($state['count'] ?? 0) : 0) + 1;
        $timestamps[] = $now;
        $freeze = $count >= $policy['limit'] ? $now + $policy['freeze'] : 0.0;
        if ($freeze > 0) { $count = 0; $timestamps = []; }
        Cache::set($key, ['count'=>$count, 'timestamps'=>$timestamps, 'frozen_until'=>$freeze], max(60, $policy['window'] + $policy['freeze'] + 10));
    }

    private function statsKey(string $accountKey): string { return self::CACHE_PREFIX.'stats:'.hash('sha256',$accountKey); }
    private function stats(string $accountKey): array { $value=Cache::get($this->statsKey($accountKey));return is_array($value)?$value:[]; }
    private function saveStats(string $accountKey,array $stats): void { Cache::set($this->statsKey($accountKey),$stats,self::STATS_TTL); }
    private function markAttempt(string $accountKey,array $account,string $token): void
    {
        $stats=$this->stats($accountKey);$now=microtime(true);$stats['call_count']=(int)($stats['call_count']??0)+1;$stats['last_used_microtime']=$now;$stats['last_used_at']=date('Y-m-d H:i:s',(int)$now);$stats['last_status']='calling';$stats['last_error']='';$this->saveStats($accountKey,$stats);
        Cache::set(self::CACHE_PREFIX.'current-account',['account_key'=>$accountKey,'id'=>(string)($account['id']??''),'username'=>(string)$account['username'],'ak'=>$token,'used_at'=>$stats['last_used_at']],self::STATS_TTL);
    }
    private function markSuccess(string $accountKey,array $account,string $token,float $started): void
    {
        $stats=$this->stats($accountKey);$stats['success_count']=(int)($stats['success_count']??0)+1;$stats['last_status']='success';$stats['last_error']='';$stats['last_duration_ms']=(int)round((microtime(true)-$started)*1000);$this->saveStats($accountKey,$stats);
    }
    private function markFailure(string $accountKey,array $account,string $message,float $started): void
    {
        $stats=$this->stats($accountKey);$stats['failure_count']=(int)($stats['failure_count']??0)+1;$stats['last_status']='failed';$stats['last_error']=mb_substr($message,0,200);$stats['last_duration_ms']=(int)round((microtime(true)-$started)*1000);$this->saveStats($accountKey,$stats);
    }
    private function markLogin(string $accountKey,array $account,string $token,float $started): void
    {
        $stats=$this->stats($accountKey);$stats['login_count']=(int)($stats['login_count']??0)+1;$stats['last_login_at']=date('Y-m-d H:i:s');$stats['last_login_duration_ms']=(int)round((microtime(true)-$started)*1000);$stats['last_status']='logged_in';$stats['last_error']='';$this->saveStats($accountKey,$stats);
    }
    private function markLoginFailure(string $accountKey,array $account,\Throwable $error,float $started): void
    {
        $stats=$this->stats($accountKey);$stats['login_failure_count']=(int)($stats['login_failure_count']??0)+1;$stats['last_status']='login_failed';$stats['last_error']=mb_substr($error->getMessage(),0,200);$stats['last_login_duration_ms']=(int)round((microtime(true)-$started)*1000);$this->saveStats($accountKey,$stats);
    }
    private function markHealthAttempt(string $accountKey): void
    {
        $stats=$this->stats($accountKey);$stats['health_check_count']=(int)($stats['health_check_count']??0)+1;$stats['last_health_at']=date('Y-m-d H:i:s');$stats['last_health_status']='checking';$stats['last_health_error']='';$this->saveStats($accountKey,$stats);
    }
    private function markHealth(string $accountKey,string $status,string $message,float $started): void
    {
        $stats=$this->stats($accountKey);
        $stats['last_health_at']=date('Y-m-d H:i:s');$stats['last_health_status']=$status;$stats['last_health_error']=$status==='failed'?mb_substr($message,0,200):'';$stats['last_health_duration_ms']=(int)round((microtime(true)-$started)*1000);$this->saveStats($accountKey,$stats);
    }
    private function scheduleBackgroundHealthCheck(): void
    {
        // A full pool check may include several captcha/login attempts. Keep
        // the deduplication lock for the whole expected run so a burst of
        // user requests does not fork many identical health workers.
        $lock=self::CACHE_PREFIX.'health-worker';if(Cache::get($lock))return;Cache::set($lock,1,self::HEALTH_WORKER_LOCK_TTL);
        $think=dirname(__DIR__,2).DIRECTORY_SEPARATOR.'think';$php=is_file('/www/server/php/82/bin/php')?'/www/server/php/82/bin/php':PHP_BINARY;
        $command='nohup '.escapeshellarg($php).' '.escapeshellarg($think).' quick-entry:health-check >/dev/null 2>&1 &';@shell_exec($command);
    }
    private function throttleState(string $accountKey,array $account): array
    {
        $policy=$this->throttlePolicy($account);$state=Cache::get(self::CACHE_PREFIX.'throttle:'.hash('sha256',$accountKey));$now=microtime(true);$timestamps=array_values(array_filter((array)(is_array($state)?($state['timestamps']??[]):[]),static fn($time):bool=>is_numeric($time)&&$policy['window']>0&&(float)$time>=$now-$policy['window']));
        return ['count'=>$policy['window']>0?count($timestamps):(int)(is_array($state)?($state['count']??0):0),'frozen_until'=>(float)(is_array($state)?($state['frozen_until']??0):0)];
    }
    private function formatTime(float $timestamp): string { return $timestamp>time()?date('Y-m-d H:i:s',(int)$timestamp):''; }
}
