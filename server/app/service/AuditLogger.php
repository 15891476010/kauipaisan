<?php
declare(strict_types=1);
namespace app\service;

use think\facade\Db;

final class AuditLogger
{
    public static function write(array $context, string $action, ?string $resource = null, array $payload = [], ?string $ip = null): void
    {
        try {
            $safe = self::sanitize($payload);
            Db::name('audit_logs')->insert([
                'tenant_id' => isset($context['tenant_id']) ? (int)$context['tenant_id'] : null,
                'agent_id' => isset($context['agent_id']) ? (int)$context['agent_id'] : null,
                'organization_id' => isset($context['organization_id']) ? (int)$context['organization_id'] : null,
                'user_id' => isset($context['user_id']) ? (int)$context['user_id'] : null,
                'username' => isset($context['username']) ? mb_substr((string)$context['username'], 0, 80) : null,
                'action' => mb_substr($action, 0, 80),
                'resource' => $resource !== null ? mb_substr($resource, 0, 120) : null,
                'ip' => $ip !== null ? mb_substr($ip, 0, 45) : null,
                'payload' => $safe ? json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never make the business request fail.
        }
    }

    public static function sanitize(array $data): array
    {
        $blocked = ['password', 'initial_password', 'manager_password', 'old_password', 'confirm_password', 'captcha', 'captcha_id', 'token', 'authorization', 'access_token'];
        $result = [];
        foreach ($data as $key => $value) {
            $key = (string)$key;
            if (in_array(strtolower($key), $blocked, true)) continue;
            if (is_array($value)) $result[$key] = self::sanitize($value);
            elseif (is_scalar($value) || $value === null) $result[$key] = is_string($value) ? mb_substr($value, 0, 500) : $value;
        }
        return $result;
    }
}
