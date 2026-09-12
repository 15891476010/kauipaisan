<?php
declare(strict_types=1);
namespace app\controller\Agent;

/** Agent API entry; explicit signatures preserve ThinkPHP argument binding. */
class SiteSettings
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\SiteSettings();
    }

    public function agentAgreement(\think\Request $request): \think\response\Json
    {
        return $this->delegate->agentAgreement($request);
    }

    public function agentAnnouncement(\think\Request $request): \think\response\Json
    {
        return $this->delegate->agentAnnouncement($request);
    }

    public function agentRules(\think\Request $request): \think\response\Json
    {
        return $this->delegate->agentRules($request);
    }
}
