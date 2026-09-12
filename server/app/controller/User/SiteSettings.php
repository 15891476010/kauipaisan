<?php
declare(strict_types=1);
namespace app\controller\User;

/** User API entry; explicit signatures preserve ThinkPHP argument binding. */
class SiteSettings
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\SiteSettings();
    }

    public function userAgreement(\think\Request $request): \think\response\Json
    {
        return $this->delegate->userAgreement($request);
    }

    public function userAnnouncement(\think\Request $request): \think\response\Json
    {
        return $this->delegate->userAnnouncement($request);
    }

    public function userRules(\think\Request $request): \think\response\Json
    {
        return $this->delegate->userRules($request);
    }
}
