<?php
declare(strict_types=1);
namespace app\controller\Agent;

/** Agent API entry; explicit signatures preserve ThinkPHP argument binding. */
class AgentBusiness
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\AgentBusiness();
    }

    public function categories(\think\Request $request): \think\response\Json
    {
        return $this->delegate->categories($request);
    }

    public function orderDetails(\think\Request $request): \think\response\Json
    {
        return $this->delegate->orderDetails($request);
    }

    public function winningDetails(\think\Request $request): \think\response\Json
    {
        return $this->delegate->winningDetails($request);
    }

    public function betRecords(\think\Request $request): \think\response\Json
    {
        return $this->delegate->betRecords($request);
    }

    public function refunds(\think\Request $request): \think\response\Json
    {
        return $this->delegate->refunds($request);
    }
}
