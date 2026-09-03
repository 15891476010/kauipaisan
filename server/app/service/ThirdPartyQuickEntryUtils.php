<?php
declare(strict_types=1);

namespace app\service;

/**
 * Wire-format helpers for the reference quick-entry API.
 *
 * Keeping these operations in a small, dependency-free utility makes the
 * adapter easy to test and prevents the local QuickEntryParser rules from
 * being changed when the upstream protocol changes.
 */
final class ThirdPartyQuickEntryUtils
{
    /** Encode an upstream request as the expected form field. */
    public static function encodeEnvelope(array $payload): string
    {
        return 'k='.rawurlencode(base64_encode(json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )));
    }

    /** Decode both the reference `{r: base64}` response and plain JSON. */
    public static function decodeEnvelope(string $body): array
    {
        // The reference endpoint labels its JSON response as text/html and
        // prefixes it with a UTF-8 BOM.  Remove transport whitespace/BOM
        // before decoding so valid JSON is accepted regardless of headers.
        $body = ltrim($body);
        if (strncmp($body, "\xEF\xBB\xBF", 3) === 0) {
            $body = substr($body, 3);
        }
        $outer = json_decode($body, true);
        if (!is_array($outer)) {
            throw new \RuntimeException('三方接口返回非 JSON');
        }

        $encoded = $outer['r'] ?? ($outer['data']['r'] ?? null);
        if (is_string($encoded) && $encoded !== '') {
            $decoded = base64_decode($encoded, true);
            if ($decoded === false) {
                // Some deployments use URL-safe base64 in the response.
                $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
            }
            $payload = $decoded === false ? null : json_decode($decoded, true);
            if (is_array($payload)) return $payload;
            throw new \RuntimeException('三方接口响应 Base64 内容无效');
        }

        if (array_key_exists('c', $outer)) {
            return [
                'code' => (int)$outer['c'],
                'message' => (string)($outer['message'] ?? '三方接口请求失败'),
            ];
        }
        return $outer;
    }

    /** Preserve the upstream placeholders used in the bet text field. */
    public static function encodeBetText(string $text): string
    {
        return str_replace(
            ['--', "'", '"', "\n", "\r"],
            ['%MNS', '%QT', '%DQ', '%NL', '%CR'],
            $text,
        );
    }

    /** Response codes observed for expired/invalid access keys. */
    public static function tokenRejected(array $response): bool
    {
        return in_array(self::responseCode($response), [201, 401, 702, 703], true);
    }

    /** Whether the provider is asking us to slow down instead of validating text. */
    public static function rateLimited(array $response): bool
    {
        $code=self::responseCode($response);
        if (in_array($code,[429,509,529],true)) return true;
        $values=[];
        $collect=function(mixed $value) use (&$collect,&$values): void {
            if (is_string($value)) { $values[]=mb_strtolower(trim($value)); return; }
            if (!is_array($value)) return;
            foreach ($value as $item) $collect($item);
        };
        $collect($response);
        foreach ($values as $text) {
            if (preg_match('/请求次数.{0,8}(超限|过多|达到)|调用次数.{0,8}(超限|过多|达到)|请求过于频繁|操作过于频繁|访问过于频繁|too many requests|rate.?limit|频率限制/u',$text)===1) return true;
        }
        return false;
    }

    public static function responseCode(array $response): int
    {
        return (int)($response['code'] ?? $response['c'] ?? $response['status'] ?? $response['data']['code'] ?? $response['data']['c'] ?? 0);
    }
}
