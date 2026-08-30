<?php
declare(strict_types=1);
namespace app\service;

final class QuickEntryToken
{
    public function __construct(
        public readonly string $kind,
        public readonly string $value,
        public readonly int $offset,
        public readonly int $length,
    ) {}
}
