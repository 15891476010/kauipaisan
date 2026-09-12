<?php
declare(strict_types=1);
namespace app\controller\Agent;

/** Agent API entry; explicit signatures preserve ThinkPHP argument binding. */
class AgentSubaccount
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\AgentSubaccount();
    }

    public function options(\think\Request $request): \think\response\Json
    {
        return $this->delegate->options($request);
    }

    public function batchDelete(\think\Request $request): \think\response\Json
    {
        return $this->delegate->batchDelete($request);
    }

    public function detail(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->detail($request, $id);
    }

    public function update(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->update($request, $id);
    }

    public function delete(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->delete($request, $id);
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
