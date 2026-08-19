<?php
declare(strict_types=1);
$env = parse_ini_file(dirname(__DIR__) . '/.env', false, INI_SCANNER_RAW);
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$name = $env['DB_DATABASE'] ?? 'kuaipaisan';
if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) throw new RuntimeException('Invalid database name');
$pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Database {$name} is ready.\n";

