<?php
declare(strict_types=1);

namespace app\controller;

use app\service\BrandingSettings;
use think\facade\Cache;
use think\Request;

final class Branding
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

    public function index(Request $request): \think\response\Json
    {
        return $this->reply(BrandingSettings::publicBranding($request));
    }

    public function adminSettings(Request $request): \think\response\Json
    {
        $this->admin($request);
        return $this->reply(['platform_name' => BrandingSettings::platformName()]);
    }

    public function saveAdminSettings(Request $request): \think\response\Json
    {
        $this->admin($request);
        $name = BrandingSettings::savePlatformName((string)$request->put('platform_name', ''));
        return $this->reply(['platform_name' => $name], '平台名称已保存');
    }
}
