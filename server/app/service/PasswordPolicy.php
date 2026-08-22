<?php
declare(strict_types=1);

namespace app\service;

final class PasswordPolicy
{
    public static function initial(string $input, string $username): string
    {
        $password = trim($input);
        if ($password === '') $password = self::generate();
        self::assertValid($password, $username);
        return $password;
    }

    public static function assertValid(string $password, string $username, ?string $currentHash = null): void
    {
        if (strlen($password) < 6 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            throw new \InvalidArgumentException('密码必须是数字和字母组合，至少6位');
        }
        if (strcasecmp($password, $username) === 0 || ($currentHash && password_verify($password, $currentHash))) {
            throw new \InvalidArgumentException('新密码不能跟账号和原密码相同');
        }
        if (in_array(strtolower($password), SecuritySettings::weakPasswords(), true)) {
            throw new \InvalidArgumentException('该密码过于简单，请更换密码');
        }
    }

    private static function generate(): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $all = $upper.$lower.$digits;
        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];
        while (count($chars) < 10) $chars[] = $all[random_int(0, strlen($all) - 1)];
        for ($index = count($chars) - 1; $index > 0; $index--) {
            $swap = random_int(0, $index);
            [$chars[$index], $chars[$swap]] = [$chars[$swap], $chars[$index]];
        }
        return implode('', $chars);
    }
}
