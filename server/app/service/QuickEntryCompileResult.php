<?php
declare(strict_types=1);

namespace app\service;

/** Result of the strict parser before settlement/odds adaptation. */
final class QuickEntryCompileResult
{
    public const NO_MATCH = 'no_match';
    public const MATCHED = 'matched';

    /** @param array<int,array<string,mixed>> $rows */
    private function __construct(
        public readonly string $state,
        public readonly array $rows = [],
    ) {}

    public static function noMatch(): self
    {
        return new self(self::NO_MATCH);
    }

    /** @param array<int,array<string,mixed>> $rows */
    public static function matched(array $rows): self
    {
        return new self(self::MATCHED, $rows);
    }

    public function matchedInput(): bool
    {
        return $this->state === self::MATCHED;
    }
}
