<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use think\facade\Cache;
use think\Request;

final class BrandingSettings
{
    private const PLATFORM_NAME_KEY = 'platform_name';
    private const DEFAULT_PLATFORM_NAME = '快排 SaaS';

    public static function platformName(): string
    {
        $value = trim((string)Db::name('settings')
            ->where('tenant_id', 1)
            ->whereNull('site_id')
            ->where('key', self::PLATFORM_NAME_KEY)
            ->value('value'));

        return $value !== '' ? $value : self::DEFAULT_PLATFORM_NAME;
    }

    public static function savePlatformName(string $name): string
    {
        $name = trim($name);
        if ($name === '') throw new \InvalidArgumentException('请输入平台名称');
        if (mb_strlen($name) > 80) throw new \InvalidArgumentException('平台名称不能超过 80 个字符');

        $now = date('Y-m-d H:i:s');
        $existing = Db::name('settings')
            ->where('tenant_id', 1)
            ->whereNull('site_id')
            ->where('key', self::PLATFORM_NAME_KEY)
            ->find();

        if ($existing) {
            Db::name('settings')->where('id', (int)$existing['id'])->update(['value' => $name, 'updated_at' => $now]);
        } else {
            Db::name('settings')->insert([
                'tenant_id' => 1,
                'site_id' => null,
                'key' => self::PLATFORM_NAME_KEY,
                'value' => $name,
                'updated_at' => $now,
            ]);
        }

        return $name;
    }

    public static function publicBranding(Request $request): array
    {
        $site = self::siteForDomain($request);
        if (!$site) $site = self::siteForSession($request);

        return [
            'platform_name' => self::platformName(),
            'site_id' => $site ? (int)$site['id'] : null,
            'site_name' => $site ? (string)$site['name'] : '',
        ];
    }

    private static function siteForSession(Request $request): ?array
    {
        $token = trim(str_ireplace('Bearer ', '', (string)$request->header('authorization')));
        $session = $token !== '' ? Cache::get('token:'.$token) : null;
        $siteId = is_array($session) && in_array((string)($session['scope'] ?? ''), ['agent', 'user'], true)
            ? (int)($session['site_id'] ?? 0)
            : 0;
        if ($siteId < 1) return null;

        return Db::name('sites')->field('id,name')->where('id', $siteId)->where('status', 1)->whereNull('deleted_at')->find() ?: null;
    }

    private static function siteForDomain(Request $request): ?array
    {
        $agentDomain = strtolower(trim((string)$request->header('x-agent-domain')));
        $userDomain = strtolower(trim((string)$request->header('x-user-domain')));
        $domain = $agentDomain !== '' ? $agentDomain : $userDomain;
        $domainType = $agentDomain !== '' ? 'agent' : 'user';
        if ($domain === '') return null;

        $domainHost = preg_replace('/:\d+$/', '', $domain) ?: $domain;
        $candidates = array_values(array_unique([
            $domain,
            $domainHost,
            'http://'.$domain,
            'https://'.$domain,
            'http://'.$domainHost,
            'https://'.$domainHost,
        ]));
        $siteId = (int)Db::name('domains')
            ->whereIn('domain', $candidates)
            ->where('domain_type', $domainType)
            ->where('status', 1)
            ->value('site_id');
        if ($siteId < 1) return null;

        return Db::name('sites')->field('id,name')->where('id', $siteId)->where('status', 1)->whereNull('deleted_at')->find() ?: null;
    }
}
