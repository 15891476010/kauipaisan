<?php
declare(strict_types=1);
namespace app\controller\Agent;

/** Agent API entry; explicit signatures preserve ThinkPHP argument binding. */
class AgentReport
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\AgentReport();
    }

    public function monthly(\think\Request $request): \think\response\Json
    {
        return $this->delegate->monthly($request);
    }

    public function issues(\think\Request $request): \think\response\Json
    {
        return $this->delegate->issues($request);
    }

    public function index(\think\Request $request): \think\response\Json
    {
        return $this->delegate->index($request);
    }
}
