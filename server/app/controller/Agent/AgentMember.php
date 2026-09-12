<?php
declare(strict_types=1);
namespace app\controller\Agent;

/** Agent API entry; explicit signatures preserve ThinkPHP argument binding. */
class AgentMember
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\AgentMember();
    }

    public function detail(\think\Request $request): \think\response\Json
    {
        return $this->delegate->detail($request);
    }

    public function update(\think\Request $request): \think\response\Json
    {
        return $this->delegate->update($request);
    }

    public function index(\think\Request $request): \think\response\Json
    {
        return $this->delegate->index($request);
    }

    public function create(\think\Request $request): \think\response\Json
    {
        return $this->delegate->create($request);
    }
}
