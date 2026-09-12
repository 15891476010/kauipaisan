<?php
declare(strict_types=1);
namespace app\controller\Saas;

/** Saas API entry; explicit signatures preserve ThinkPHP argument binding. */
class SiteSettings
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\SiteSettings();
    }

    public function adminAgreement(\think\Request $request): \think\response\Json
    {
        return $this->delegate->adminAgreement($request);
    }

    public function saveAdminAgreement(\think\Request $request): \think\response\Json
    {
        return $this->delegate->saveAdminAgreement($request);
    }

    public function adminAnnouncement(\think\Request $request): \think\response\Json
    {
        return $this->delegate->adminAnnouncement($request);
    }

    public function saveAdminAnnouncement(\think\Request $request): \think\response\Json
    {
        return $this->delegate->saveAdminAnnouncement($request);
    }

    public function adminAgentAgreement(\think\Request $request): \think\response\Json
    {
        return $this->delegate->adminAgentAgreement($request);
    }

    public function saveAdminAgentAgreement(\think\Request $request): \think\response\Json
    {
        return $this->delegate->saveAdminAgentAgreement($request);
    }

    public function adminAgentAnnouncement(\think\Request $request): \think\response\Json
    {
        return $this->delegate->adminAgentAnnouncement($request);
    }

    public function saveAdminAgentAnnouncement(\think\Request $request): \think\response\Json
    {
        return $this->delegate->saveAdminAgentAnnouncement($request);
    }

    public function adminRules(\think\Request $request): \think\response\Json
    {
        return $this->delegate->adminRules($request);
    }

    public function saveAdminRules(\think\Request $request): \think\response\Json
    {
        return $this->delegate->saveAdminRules($request);
    }

    public function adminBettingControls(\think\Request $request): \think\response\Json
    {
        return $this->delegate->adminBettingControls($request);
    }

    public function saveAdminBettingControls(\think\Request $request): \think\response\Json
    {
        return $this->delegate->saveAdminBettingControls($request);
    }
}
