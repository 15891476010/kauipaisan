<?php
declare(strict_types=1);
namespace app\controller\Saas;

/** Saas API entry; explicit signatures preserve ThinkPHP argument binding. */
class AdminRobots
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\AdminRobots();
    }

    public function options(\think\Request $request): \think\response\Json
    {
        return $this->delegate->options($request);
    }

    public function index(\think\Request $request): \think\response\Json
    {
        return $this->delegate->index($request);
    }

    public function create(\think\Request $request): \think\response\Json
    {
        return $this->delegate->create($request);
    }

    public function update(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->update($request, $id);
    }

    public function status(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->status($request, $id);
    }

    public function logs(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->logs($request, $id);
    }

    public function convert(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->convert($request, $id);
    }

    public function history(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->history($request, $id);
    }

    public function clearLatest(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->clearLatest($request, $id);
    }
}
