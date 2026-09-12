<?php
declare(strict_types=1);
namespace app\controller\Saas;

/** Saas API entry; explicit signatures preserve ThinkPHP argument binding. */
class Auth
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\Auth();
    }

    public function adminLogin(\think\Request $request): \think\response\Json
    {
        return $this->delegate->adminLogin($request);
    }

    public function refresh(\think\Request $request): \think\response\Json
    {
        return $this->delegate->refresh($request);
    }

    public function logout(\think\Request $request): \think\response\Json
    {
        return $this->delegate->logout($request);
    }

    public function heartbeat(\think\Request $request): \think\response\Json
    {
        return $this->delegate->heartbeat($request);
    }

    public function menus(\think\Request $request): \think\response\Json
    {
        return $this->delegate->menus($request);
    }

    public function adminEnter(\think\Request $request): \think\response\Json
    {
        return $this->delegate->adminEnter($request);
    }
}
