<?php
declare(strict_types=1);
namespace app\controller\Saas;

/** Saas API entry; explicit signatures preserve ThinkPHP argument binding. */
class Resource
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\Resource();
    }

    public function index(\think\Request $request, string $resource): \think\response\Json
    {
        return $this->delegate->index($request, $resource);
    }

    public function create(\think\Request $request, string $resource): \think\response\Json
    {
        return $this->delegate->create($request, $resource);
    }

    public function update(\think\Request $request, string $resource, int $id): \think\response\Json
    {
        return $this->delegate->update($request, $resource, $id);
    }

    public function delete(\think\Request $request, string $resource, int $id): \think\response\Json
    {
        return $this->delegate->delete($request, $resource, $id);
    }

    public function clearAuditLogs(\think\Request $request): \think\response\Json
    {
        return $this->delegate->clearAuditLogs($request);
    }

    public function auditDetail(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->auditDetail($request, $id);
    }

    public function betDetails(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->betDetails($request, $id);
    }

    public function updateBetDetail(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->updateBetDetail($request, $id);
    }
}
