<?php
declare(strict_types=1);
namespace app\controller\Agent;

/** Agent API entry; explicit signatures preserve ThinkPHP argument binding. */
class AuditLog
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\AuditLog();
    }

    public function index(\think\Request $request): \think\response\Json
    {
        return $this->delegate->index($request);
    }
}
