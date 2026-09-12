<?php
declare(strict_types=1);
namespace app\controller\Saas;

/** Saas API entry; explicit signatures preserve ThinkPHP argument binding. */
class Organization
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\Organization();
    }

    public function adminIndex(\think\Request $request, int $siteId): \think\response\Json
    {
        return $this->delegate->adminIndex($request, $siteId);
    }

    public function adminCreateNode(\think\Request $request, int $siteId): \think\response\Json
    {
        return $this->delegate->adminCreateNode($request, $siteId);
    }

    public function adminSetDirectorCreditShare(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->adminSetDirectorCreditShare($request, $id);
    }

    public function adminSetDirectorCredit(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->adminSetDirectorCredit($request, $id);
    }

    public function adminUpdateNode(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->adminUpdateNode($request, $id);
    }

    public function adminDeleteNode(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->adminDeleteNode($request, $id);
    }

    public function adminCreateAccount(\think\Request $request, int $organizationId): \think\response\Json
    {
        return $this->delegate->adminCreateAccount($request, $organizationId);
    }

    public function adminUpdateAccount(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->adminUpdateAccount($request, $id);
    }

    public function adminDeleteAccount(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->adminDeleteAccount($request, $id);
    }

    public function adminProfitShare(\think\Request $request, int $siteId): \think\response\Json
    {
        return $this->delegate->adminProfitShare($request, $siteId);
    }

    public function adminSaveProfitShare(\think\Request $request, int $siteId, int $childId): \think\response\Json
    {
        return $this->delegate->adminSaveProfitShare($request, $siteId, $childId);
    }
}
