<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class SecuritySettings
{
    private const KEY = 'security_weak_passwords';
    private const DEFAULT_WEAK_PASSWORDS = ['a12345', 'ab1234', 'abc123', 'a1b2c3', 'aaa111', '123qwe'];

    public static function weakPasswords(): array
    {
        $value = Db::name('settings')->where('tenant_id', 1)->whereNull('site_id')->where('key', self::KEY)->value('value');
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? self::normalize($decoded) : self::DEFAULT_WEAK_PASSWORDS;
    }

    public static function saveWeakPasswords(array $passwords): array
    {
        $normalized = self::normalize($passwords);
        if ($normalized === []) throw new \InvalidArgumentException('弱密码列表不能为空');
        $value = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = date('Y-m-d H:i:s');
        $existing = Db::name('settings')->where('tenant_id', 1)->whereNull('site_id')->where('key', self::KEY)->find();
        if ($existing) Db::name('settings')->where('id', $existing['id'])->update(['value' => $value, 'updated_at' => $now]);
        else Db::name('settings')->insert(['tenant_id' => 1, 'site_id' => null, 'key' => self::KEY, 'value' => $value, 'updated_at' => $now]);
        return $normalized;
    }

    private static function normalize(array $passwords): array
    {
        $result = [];
        foreach ($passwords as $password) {
            if (!is_scalar($password)) continue;
            $password = mb_strtolower(trim((string)$password));
            if ($password === '' || mb_strlen($password) > 64 || isset($result[$password])) continue;
            $result[$password] = $password;
            if (count($result) >= 200) break;
        }
        return array_values($result);
    }
}
