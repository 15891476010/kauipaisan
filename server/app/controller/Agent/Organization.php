<?php
declare(strict_types=1);
namespace app\controller\Agent;

/** Agent API entry; explicit signatures preserve ThinkPHP argument binding. */
class Organization
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\Organization();
    }

    public function profile(\think\Request $request): \think\response\Json
    {
        return $this->delegate->profile($request);
    }

    public function agentIndex(\think\Request $request): \think\response\Json
    {
        return $this->delegate->agentIndex($request);
    }

    public function agentCreateNode(\think\Request $request): \think\response\Json
    {
        return $this->delegate->agentCreateNode($request);
    }

    public function agentUpdateNode(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->agentUpdateNode($request, $id);
    }

    public function agentDeleteNode(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->agentDeleteNode($request, $id);
    }

    public function agentCreateAccount(\think\Request $request, int $organizationId): \think\response\Json
    {
        return $this->delegate->agentCreateAccount($request, $organizationId);
    }

    public function agentUpdateAccount(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->agentUpdateAccount($request, $id);
    }

    public function agentDeleteAccount(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->agentDeleteAccount($request, $id);
    }

    public function agentProfitShare(\think\Request $request): \think\response\Json
    {
        return $this->delegate->agentProfitShare($request);
    }

    public function agentSaveProfitShare(\think\Request $request, int $childId): \think\response\Json
    {
        return $this->delegate->agentSaveProfitShare($request, $childId);
    }
}
