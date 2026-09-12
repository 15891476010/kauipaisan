<?php
declare(strict_types=1);
namespace app\controller\Saas;

/** Saas API entry; explicit signatures preserve ThinkPHP argument binding. */
class AdminBetBatch
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\AdminBetBatch();
    }

    public function options(\think\Request $request): \think\response\Json
    {
        return $this->delegate->options($request);
    }

    public function replace(\think\Request $request): \think\response\Json
    {
        return $this->delegate->replace($request);
    }

    public function recordOptions(\think\Request $request): \think\response\Json
    {
        return $this->delegate->recordOptions($request);
    }

    public function preview(\think\Request $request): \think\response\Json
    {
        return $this->delegate->preview($request);
    }

    public function apply(\think\Request $request): \think\response\Json
    {
        return $this->delegate->apply($request);
    }
}
