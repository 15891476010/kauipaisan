<?php
declare(strict_types=1);
namespace app\controller\Saas;

/** Saas API entry; explicit signatures preserve ThinkPHP argument binding. */
class ThirdPartyQuickEntry
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\ThirdPartyQuickEntry();
    }

    public function config(\think\Request $request): \think\response\Json
    {
        return $this->delegate->config($request);
    }

    public function saveConfig(\think\Request $request): \think\response\Json
    {
        return $this->delegate->saveConfig($request);
    }

    public function test(\think\Request $request): \think\response\Json
    {
        return $this->delegate->test($request);
    }

    public function loginAccount(\think\Request $request, string $accountId = ''): \think\response\Json
    {
        return $this->delegate->loginAccount($request, $accountId);
    }
}
