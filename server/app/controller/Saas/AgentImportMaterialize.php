<?php
declare(strict_types=1);
namespace app\controller\Saas;

/** Saas API entry; explicit signatures preserve ThinkPHP argument binding. */
class AgentImportMaterialize
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\AgentImportMaterialize();
    }

    public function credentials(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->credentials($request, $id);
    }

    public function logs(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->logs($request, $id);
    }

    public function createBatch(\think\Request $request): \think\response\Json
    {
        return $this->delegate->createBatch($request);
    }

    public function rollback(\think\Request $request): \think\response\Json
    {
        return $this->delegate->rollback($request);
    }
}
