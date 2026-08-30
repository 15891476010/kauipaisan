<?php
declare(strict_types=1);

namespace app\service;

/**
 * Immutable-ish context produced by the staged quick-entry scanner.
 * Recognition is intentionally independent from settlement formatting.
 */
final class QuickEntryStageContext
{
    /** @param array<int,string> $numbers @param array<int,string> $plays */
    public function __construct(
        public readonly string $source,
        public readonly string $lottery,
        public readonly string $category,
        public readonly array $numbers,
        public readonly array $plays,
        public readonly ?float $amount,
        public readonly ?string $amountUnit,
        public readonly ?string $errorStage = null,
        public readonly ?string $error = null,
        public readonly ?LotteryCode $lotteryCode = null,
        public readonly array $playCodes = [],
        public readonly ?ParseStage $errorCode = null,
        /** @var array<int,array{value:float,unit:?string}> */
        public readonly array $amounts = [],
    ) {}

    public function failed(): bool
    {
        return $this->errorStage !== null;
    }
}
