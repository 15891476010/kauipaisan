<?php
declare(strict_types=1);
namespace app\controller\Agent;

/** Agent API entry; explicit signatures preserve ThinkPHP argument binding. */
class AgentInterception
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\AgentInterception();
    }

    public function issues(\think\Request $request): \think\response\Json
    {
        return $this->delegate->issues($request);
    }

    public function categories(\think\Request $request): \think\response\Json
    {
        return $this->delegate->categories($request);
    }

    public function plate(\think\Request $request): \think\response\Json
    {
        return $this->delegate->plate($request);
    }

    public function index(\think\Request $request): \think\response\Json
    {
        return $this->delegate->index($request);
    }
}
