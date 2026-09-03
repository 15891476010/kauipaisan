<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Configuration and secret storage for the optional third-party quick-entry
 * adapter. The local QuickEntryParser remains the default provider.
 */
final class ThirdPartyQuickEntryConfig
{
    public const SETTING_KEY = 'quick_entry_third_party';
    public const PASSWORD_MASK = '********';

    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'strict' => false,
            'base_url' => '',
            'captcha_endpoint' => '/vc/qc.php',
            'login_endpoint' => '/mb/',
            'recognize_endpoint' => '/mb/',
            'captcha_ocr_endpoint' => '',
            'captcha_ocr_command' => 'tesseract',
            'captcha_ocr_language' => 'chi_sim+eng',
            'request_timeout' => 15,
            'token_ttl_seconds' => 28800,
            'rate_window_seconds' => 0,
            'freeze_after_calls' => 3,
            'freeze_seconds' => 3,
            'accounts' => [],
        ];
    }

    public static function load(int $tenantId, ?int $siteId = null): array
    {
        $defaults = self::defaults();
        $row = null;
        if ($siteId !== null && $siteId > 0) {
            $row = Db::name('settings')->where('tenant_id', $tenantId)->where('site_id', $siteId)
                ->where('key', self::SETTING_KEY)->find();
        }
        if (!$row) {
            $row = Db::name('settings')->where('tenant_id', $tenantId)->whereNull('site_id')
                ->where('key', self::SETTING_KEY)->find();
        }
        $stored = $row ? json_decode((string)($row['value'] ?? ''), true) : [];
        $config = array_replace($defaults, is_array($stored) ? $stored : []);
        $config['accounts'] = self::normalizeAccounts((array)($config['accounts'] ?? []), true);
        $config['enabled'] = (bool)$config['enabled'];
        $config['strict'] = (bool)$config['strict'];
        $config['request_timeout'] = max(1, min(60, (int)$config['request_timeout']));
        $config['token_ttl_seconds'] = max(60, min(604800, (int)$config['token_ttl_seconds']));
        $config['rate_window_seconds'] = max(0, min(86400, (int)$config['rate_window_seconds']));
        $config['freeze_after_calls'] = max(1, min(1000, (int)$config['freeze_after_calls']));
        $config['freeze_seconds'] = max(0, min(300, (int)$config['freeze_seconds']));
        return $config;
    }

    public static function save(int $tenantId, ?int $siteId, array $input): array
    {
        $current = self::load($tenantId, $siteId);
        $config = array_replace($current, $input);
        $config['base_url'] = rtrim(trim((string)($config['base_url'] ?? '')), '/');
        if ($config['base_url'] !== '' && !filter_var($config['base_url'], FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('三方平台 Base URL 无效');
        }
        foreach (['captcha_endpoint', 'login_endpoint', 'recognize_endpoint'] as $field) {
            $value = trim((string)($config[$field] ?? ''));
            if ($value === '' || (!str_starts_with($value, '/') && !filter_var($value, FILTER_VALIDATE_URL))) {
                throw new \InvalidArgumentException($field.' 必须是 /path 或完整 URL');
            }
            $config[$field] = $value;
        }
        $config['captcha_ocr_endpoint'] = trim((string)($config['captcha_ocr_endpoint'] ?? ''));
        if ($config['captcha_ocr_endpoint'] !== ''
            && !str_starts_with($config['captcha_ocr_endpoint'], '/')
            && !filter_var($config['captcha_ocr_endpoint'], FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('captcha_ocr_endpoint 必须是 /path 或完整 URL');
        }
        $config['captcha_ocr_command'] = trim((string)($config['captcha_ocr_command'] ?? ''));
        $config['captcha_ocr_language'] = trim((string)($config['captcha_ocr_language'] ?? 'chi_sim+eng'));
        if ($config['captcha_ocr_language'] !== '' && preg_match('/^[A-Za-z0-9_+.-]+$/', $config['captcha_ocr_language']) !== 1) {
            throw new \InvalidArgumentException('captcha_ocr_language 格式无效');
        }
        $config['enabled'] = (bool)($config['enabled'] ?? false);
        $config['strict'] = (bool)($config['strict'] ?? false);
        $config['request_timeout'] = max(1, min(60, (int)($config['request_timeout'] ?? 15)));
        $config['token_ttl_seconds'] = max(60, min(604800, (int)($config['token_ttl_seconds'] ?? 28800)));
        $config['rate_window_seconds'] = max(0, min(86400, (int)($config['rate_window_seconds'] ?? 0)));
        $config['freeze_after_calls'] = max(1, min(1000, (int)($config['freeze_after_calls'] ?? 3)));
        $config['freeze_seconds'] = max(0, min(300, (int)($config['freeze_seconds'] ?? 3)));
        $config['accounts'] = self::normalizeAccounts((array)($config['accounts'] ?? []), false, $current['accounts'] ?? []);
        if ($config['enabled'] && $config['base_url'] === '') throw new \InvalidArgumentException('启用三方识别前请填写 Base URL');
        if ($config['enabled'] && $config['accounts'] === []) throw new \InvalidArgumentException('启用三方识别前请至少配置一个账号');
        if ($config['enabled'] && array_filter($config['accounts'], static fn(array $account): bool => trim((string)($account['password'] ?? '')) === '') !== []) {
            throw new \InvalidArgumentException('启用三方识别前请为账号池中的每个账号填写密码');
        }

        // Keep only encrypted passwords in storage. The publicView() method
        // masks them before anything is returned to the admin UI.
        $persist = $config;
        $value = json_encode($persist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $where = Db::name('settings')->where('tenant_id', $tenantId)->where('key', self::SETTING_KEY);
        if ($siteId === null || $siteId < 1) $where->whereNull('site_id'); else $where->where('site_id', $siteId);
        $row = $where->find();
        $data = ['tenant_id' => $tenantId, 'site_id' => $siteId, 'key' => self::SETTING_KEY, 'value' => $value, 'updated_at' => date('Y-m-d H:i:s')];
        if ($row) Db::name('settings')->where('id', (int)$row['id'])->update($data); else Db::name('settings')->insert($data);
        return $config;
    }

    public static function publicView(array $config): array
    {
        $view = $config;
        $view['accounts'] = array_map(static function (array $account): array {
            return [
                'id' => (string)$account['id'],
                'username' => (string)$account['username'],
                'password' => self::PASSWORD_MASK,
                'rate_window_seconds' => $account['rate_window_seconds'] ?? null,
                'rate_limit_calls' => $account['rate_limit_calls'] ?? null,
                'freeze_seconds' => $account['freeze_seconds'] ?? null,
            ];
        }, (array)($config['accounts'] ?? []));
        return $view;
    }

    private static function normalizeAccounts(array $accounts, bool $decrypt, array $previous = []): array
    {
        $previousByUser = [];
        foreach ($previous as $account) if (is_array($account) && ($account['username'] ?? '') !== '') $previousByUser[(string)$account['username']] = $account;
        $result = [];
        foreach ($accounts as $index => $account) {
            if (is_string($account)) $account = ['username' => $account];
            if (!is_array($account)) continue;
            $username = trim((string)($account['username'] ?? ''));
            if ($username === '' || isset($result[$username])) continue;
            $id = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', (string)($account['id'] ?? $username)) ?: ('account-'.$index);
            $password = (string)($account['password'] ?? '');
            if ($password === '' || $password === self::PASSWORD_MASK) $password = (string)($previousByUser[$username]['password'] ?? '');
            if ($decrypt && str_starts_with($password, 'enc:v1:')) $password = self::decrypt($password);
            if (!$decrypt && $password !== '' && !str_starts_with($password, 'enc:v1:')) $password = self::encrypt($password);
            $window = $account['rate_window_seconds'] ?? null;
            $limit = $account['rate_limit_calls'] ?? null;
            $freeze = $account['freeze_seconds'] ?? null;
            $result[$username] = [
                'id' => $id,
                'username' => $username,
                'password' => $password,
                // Null means “use the global default”. Keeping the override
                // on the account makes each credential independently tunable.
                'rate_window_seconds' => ($window === null || $window === '') ? null : max(0, min(86400, (int)$window)),
                'rate_limit_calls' => ($limit === null || $limit === '') ? null : max(1, min(1000, (int)$limit)),
                'freeze_seconds' => ($freeze === null || $freeze === '') ? null : max(0, min(3600, (int)$freeze)),
            ];
        }
        return array_values($result);
    }

    private static function key(): string
    {
        $raw = getenv('APP_KEY') ?: (function_exists('config') ? (string)config('app.app_key', '') : '');
        // APP_KEY is preferred when the application provides one. Older
        // installations may not have it configured, so create a persistent
        // local key instead of blocking the SaaS settings form.
        if ($raw === '') {
            $path = dirname(__DIR__, 2).'/runtime/quick-entry.key';
            if (!is_file($path)) {
                $dir = dirname($path);
                if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                    throw new \RuntimeException('无法创建三方账号密钥目录');
                }
                $key = bin2hex(random_bytes(32));
                if (@file_put_contents($path, $key, LOCK_EX) === false) {
                    throw new \RuntimeException('无法写入三方账号密钥');
                }
                @chmod($path, 0600);
            }
            $raw = trim((string)@file_get_contents($path));
            if ($raw === '') throw new \RuntimeException('三方账号密钥为空');
        }
        return hash('sha256', $raw, true);
    }

    private static function encrypt(string $plain): string
    {
        if ($plain === '') return '';
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new \RuntimeException('三方账号密码加密失败');
        return 'enc:v1:'.base64_encode($iv.$tag.$cipher);
    }

    private static function decrypt(string $encoded): string
    {
        $raw = base64_decode(substr($encoded, 7), true);
        if ($raw === false || strlen($raw) < 28) throw new \RuntimeException('三方账号密码密文无效');
        $iv = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) throw new \RuntimeException('三方账号密码解密失败');
        return $plain;
    }
}
