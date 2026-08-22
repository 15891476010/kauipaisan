<?php
declare(strict_types=1);

namespace app\service;

use think\Request;
use think\facade\Cache;
use think\facade\Db;

final class AccountPresence
{
    public const ONLINE_SECONDS = 90;

    public static function login(Request $request, string $token, array $session, string $accountType, int $accountId, ?string $loginAt=null): void
    {
        try {
            $ip=self::clientIp($request);
            $now=date('Y-m-d H:i:s');
            Db::name('account_sessions')->insert([
                'token_hash'=>hash('sha256',$token),
                'account_type'=>$accountType,
                'account_id'=>$accountId,
                'tenant_id'=>isset($session['tenant_id'])?(int)$session['tenant_id']:null,
                'agent_id'=>isset($session['agent_id'])?(int)$session['agent_id']:null,
                'site_id'=>isset($session['site_id'])?(int)$session['site_id']:null,
                'organization_id'=>isset($session['organization_id'])?(int)$session['organization_id']:null,
                'ip'=>$ip,
                'location'=>self::location($request,$ip),
                'device'=>self::device((string)$request->header('user-agent')),
                'user_agent'=>mb_substr((string)$request->header('user-agent'),0,500),
                'login_at'=>$loginAt?:$now,
                'last_seen_at'=>$now,
            ]);
        } catch (\Throwable $e) {
            // Presence tracking must not block authentication.
        }
    }

    public static function resume(Request $request, string $token, array $session): void
    {
        if ($token==='') return;
        try {
            if (Db::name('account_sessions')->where('token_hash',hash('sha256',$token))->find()) return;
            $scope=(string)($session['scope']??''); $table=(string)($session['account_table']??'');
            [$accountType,$accountTable]=match($scope) {
                'admin'=>(($session['admin_role']??'platform')==='site'?['site_admin','site_admins']:['platform_admin','admins']),
                'agent'=>match($table){'site_admins'=>['site_admin','site_admins'],'agent_subaccounts'=>['agent_subaccount','agent_subaccounts'],'organization_accounts'=>['organization_account','organization_accounts'],'sites'=>['legacy_site_admin','sites'],default=>['agent_admin','agent_admins']},
                'user'=>['site_user','site_users'],
                default=>['',''],
            };
            $accountId=(int)($session['user_id']??0);
            if ($accountType==='' || $accountId<1) return;
            $lastLogin=(string)(Db::name($accountTable)->where('id',$accountId)->value('last_login_at')?:'');
            self::login($request,$token,$session,$accountType,$accountId,$lastLogin?:null);
        } catch (\Throwable $e) {
        }
    }

    public static function touch(string $token, array $session, bool $force=false): void
    {
        if ($token==='') return;
        $hash=hash('sha256',$token);
        $throttle='presence-touch:'.$hash;
        if (!$force && Cache::get($throttle)) return;
        try {
            Db::name('account_sessions')->where('token_hash',$hash)->whereNull('logged_out_at')->update(['last_seen_at'=>date('Y-m-d H:i:s')]);
            Cache::set($throttle,1,10);
        } catch (\Throwable $e) {
            // Existing deployments can continue while the migration is pending.
        }
    }

    public static function logout(string $token): void
    {
        if ($token==='') return;
        try {
            $now=date('Y-m-d H:i:s');
            Db::name('account_sessions')->where('token_hash',hash('sha256',$token))->whereNull('logged_out_at')->update(['last_seen_at'=>$now,'logged_out_at'=>$now]);
            Cache::delete('presence-touch:'.hash('sha256',$token));
        } catch (\Throwable $e) {
        }
    }

    public static function append(array &$rows, string $accountType): void
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',array_column($rows,'id')))));
        if (!$ids) return;
        try {
            $sessions=Db::name('account_sessions')->where('account_type',$accountType)->whereIn('account_id',$ids)->order('login_at desc')->order('id desc')->select()->toArray();
        } catch (\Throwable $e) {
            $sessions=[];
        }
        $latest=[]; $online=[]; $threshold=date('Y-m-d H:i:s',time()-self::ONLINE_SECONDS);
        foreach ($sessions as $session) {
            $id=(int)$session['account_id'];
            if (!isset($latest[$id])) $latest[$id]=$session;
            if ($session['logged_out_at']===null && (string)$session['last_seen_at'] >= $threshold) $online[$id]=true;
        }
        foreach ($rows as &$row) {
            $id=(int)$row['id']; $session=$latest[$id]??[];
            $row['online']=isset($online[$id])?1:0;
            $row['last_seen_at']=$session['last_seen_at']??null;
            $row['last_login_at']=$session['login_at']??($row['last_login_at']??null);
            $row['last_login_ip']=$session['ip']??null;
            $row['last_login_location']=$session['location']??null;
            $row['last_login_device']=$session['device']??null;
        }
        unset($row);
    }

    private static function clientIp(Request $request): string
    {
        foreach (['cf-connecting-ip','x-real-ip'] as $header) {
            $value=trim((string)$request->header($header));
            if (filter_var($value,FILTER_VALIDATE_IP)) return mb_substr($value,0,45);
        }
        return mb_substr((string)$request->ip(),0,45);
    }

    private static function location(Request $request, string $ip): string
    {
        $parts=[];
        foreach (['cf-ipcountry','cf-region','cf-ipcity'] as $header) {
            $value=trim(urldecode((string)$request->header($header)));
            if ($value!=='' && !in_array($value,$parts,true)) $parts[]=$value;
        }
        if ($parts) return mb_substr(implode(' ',$parts),0,180);
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return '内网或本机地址';
        $cacheKey='ip-location:'.sha1($ip); $cached=Cache::get($cacheKey);
        if (is_string($cached) && $cached!=='') return $cached;
        $location='公网地址';
        if (function_exists('curl_init')) {
            $curl=curl_init('http://ip-api.com/json/'.rawurlencode($ip).'?lang=zh-CN&fields=status,country,regionName,city');
            curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>800,CURLOPT_TIMEOUT_MS=>1200]);
            $result=curl_exec($curl); curl_close($curl);
            $decoded=is_string($result)?json_decode($result,true):null;
            if (is_array($decoded) && ($decoded['status']??'')==='success') {
                $values=array_values(array_filter([(string)($decoded['country']??''),(string)($decoded['regionName']??''),(string)($decoded['city']??'')]));
                if ($values) $location=implode(' ',$values);
            }
        }
        Cache::set($cacheKey,$location,604800);
        return mb_substr($location,0,180);
    }

    private static function device(string $userAgent): string
    {
        $os=match(true) {
            stripos($userAgent,'Windows')!==false=>'Windows',
            stripos($userAgent,'Android')!==false=>'Android',
            preg_match('/iPhone|iPad/i',$userAgent)===1=>'iOS',
            stripos($userAgent,'Mac OS')!==false=>'macOS',
            stripos($userAgent,'Linux')!==false=>'Linux',
            default=>'未知系统',
        };
        $browser=match(true) {
            stripos($userAgent,'Edg/')!==false=>'Edge',
            stripos($userAgent,'Chrome/')!==false=>'Chrome',
            stripos($userAgent,'Firefox/')!==false=>'Firefox',
            stripos($userAgent,'Safari/')!==false=>'Safari',
            default=>'未知浏览器',
        };
        $kind=preg_match('/Mobile|Android|iPhone/i',$userAgent)===1?'移动端':'电脑端';
        return $kind.' · '.$os.' · '.$browser;
    }
}
