<?php
declare(strict_types=1);
namespace app\controller\Saas;

/** Saas API entry; explicit signatures preserve ThinkPHP argument binding. */
class ScoreLedger
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\ScoreLedger();
    }

    public function overview(\think\Request $request): \think\response\Json
    {
        return $this->delegate->overview($request);
    }

    public function dashboard(\think\Request $request): \think\response\Json
    {
        return $this->delegate->dashboard($request);
    }

    public function updateTotal(\think\Request $request): \think\response\Json
    {
        return $this->delegate->updateTotal($request);
    }

    public function detail(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->detail($request, $id);
    }

    public function index(\think\Request $request): \think\response\Json
    {
        return $this->delegate->index($request);
    }
}
