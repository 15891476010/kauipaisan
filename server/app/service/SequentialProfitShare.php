<?php
declare(strict_types=1);

namespace app\service;

/**
 * Allocate one betting line from the member's nearest organization upwards.
 *
 * Every organization keeps its configured percentage of the original member
 * turnover/result directly (share_rate is a direct fraction of the book, not
 * an edge rate on the residual). The remainder after all configured shares
 * stays with the platform.
 */
final class SequentialProfitShare
{
    /**
     * @param array<int,array<string,mixed>> $leafToRoot
     * @return array<int,array{node:array<string,mixed>,incoming_amount:float,share_rate:float,amount:float,remaining_amount:float}>
     */
    public static function allocate(float $profit, array $leafToRoot, float $rateCap = 100.0): array
    {
        if ($leafToRoot === []) return [];

        $rateCap = max(0.0, min(100.0, $rateCap));
        $covered = 0.0;
        $allocations = [];

        foreach ($leafToRoot as $node) {
            // A node can never hold more than the book that remains — total
            // configured shares are clamped at 100% of the member result.
            $rate = max(0.0, min($rateCap, min((float)($node['share_rate'] ?? 0.0), (1.0 - $covered) * 100.0)));
            $incoming = round($profit * (1.0 - $covered), 2);
            $amount = round($profit * $rate / 100, 2);
            $covered += $rate / 100;
            $remaining = round($profit * max(0.0, 1.0 - $covered), 2);
            $allocations[] = [
                'node' => $node,
                'incoming_amount' => $incoming,
                'share_rate' => $rate,
                'amount' => $amount,
                'remaining_amount' => $remaining,
            ];
        }

        return $allocations;
    }
}
