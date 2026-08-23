<?php
declare(strict_types=1);

namespace app\controller;

use app\service\SecuritySettings as SecuritySettingsService;
use think\facade\Cache;
use think\Request;

final class SecuritySettings
{
    private function reply(mixed $data = null, string $message = 'ok'): \think\response\Json
    {
        return json(['code' => 0, 'message' => $message, 'data' => $data, 'request_id' => bin2hex(random_bytes(8))]);
    }

    private function admin(Request $request): void
    {
        $token = trim(str_ireplace('Bearer ', '', (string)$request->header('authorization')));
        $session = $token !== '' ? Cache::get('token:'.$token) : null;
        if (!is_array($session) || ($session['scope'] ?? '') !== 'admin') throw new \RuntimeException('未登录或登录已过期');
    }

    public function policy(): \think\response\Json
    {
        return $this->reply(['weak_passwords' => SecuritySettingsService::weakPasswords(), 'minimum_length' => 6, 'requires_letter' => true, 'requires_number' => true]);
    }

    public function adminPolicy(Request $request): \think\response\Json
    {
        $this->admin($request);
        return $this->policy();
    }

    public function saveAdminPolicy(Request $request): \think\response\Json
    {
        $this->admin($request);
        $passwords = $request->put('weak_passwords', []);
        if (!is_array($passwords)) throw new \InvalidArgumentException('弱密码列表格式不正确');
        return $this->reply(['weak_passwords' => SecuritySettingsService::saveWeakPasswords($passwords)], '密码安全配置已保存');
    }
}
