<?php
declare(strict_types=1);
namespace app\controller\Saas;

/** Saas API entry; explicit signatures preserve ThinkPHP argument binding. */
class SecuritySettings
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\SecuritySettings();
    }

    public function adminPolicy(\think\Request $request): \think\response\Json
    {
        return $this->delegate->adminPolicy($request);
    }

    public function saveAdminPolicy(\think\Request $request): \think\response\Json
    {
        return $this->delegate->saveAdminPolicy($request);
    }
}
