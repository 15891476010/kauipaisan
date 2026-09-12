<?php
declare(strict_types=1);
namespace app\controller\Saas;

/** Saas API entry; explicit signatures preserve ThinkPHP argument binding. */
class BetAggregationTable
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\BetAggregationTable();
    }

    public function index(\think\Request $request): \think\response\Json
    {
        return $this->delegate->index($request);
    }

    public function details(\think\Request $request): \think\response\Json
    {
        return $this->delegate->details($request);
    }
}
