<?php
declare(strict_types=1);
namespace app\middleware;
use Closure;
use think\Request;
use think\Response;
use think\facade\Cache;

final class ApiContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim((string)$request->pathinfo(), '/');
        if (str_contains($path, 'admin/') && !str_ends_with($path, 'admin/auth/login')) {
            $authorization = (string)$request->header('authorization');
            $token = trim(str_ireplace('Bearer ', '', $authorization));
            $session = $token !== '' ? Cache::get('token:' . $token) : null;
            if (!is_array($session) || ($session['scope'] ?? '') !== 'admin') {
                return json(['code'=>401,'message'=>'未登录或登录已过期','data'=>null,'request_id'=>bin2hex(random_bytes(8))], 401);
            }
        }
        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $message = env('APP_DEBUG', false) ? $e->getMessage() : '服务器内部错误';
            return json(['code'=>500,'message'=>$message,'data'=>null,'request_id'=>bin2hex(random_bytes(8))], 500);
        }
        $response->header(['Access-Control-Allow-Origin' => $request->header('origin', '*'), 'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Agent-Domain', 'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS']);
        if ($request->method(true) === 'OPTIONS') return response('', 204);
        return $response;
    }
}
