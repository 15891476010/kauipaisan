<?php
declare(strict_types=1);
namespace app\controller\User;

/** User API entry; explicit signatures preserve ThinkPHP argument binding. */
class Auth
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\Auth();
    }

    public function userLogin(\think\Request $request): \think\response\Json
    {
        return $this->delegate->userLogin($request);
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

    public function captcha(): \think\response\Json
    {
        return $this->delegate->captcha();
    }

    public function verifyCaptcha(\think\Request $request): \think\response\Json
    {
        return $this->delegate->verifyCaptcha($request);
    }

    public function userProfile(\think\Request $request): \think\response\Json
    {
        return $this->delegate->userProfile($request);
    }

    public function changeUserPassword(\think\Request $request): \think\response\Json
    {
        return $this->delegate->changeUserPassword($request);
    }
}
