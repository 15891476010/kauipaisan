<?php
declare(strict_types=1);

namespace app\service;

/**
 * Allocate one betting line from the member's nearest organization upwards.
 *
     * Every non-root organization keeps its configured percentage of the amount
     * that reached it. The remainder is passed to its parent. The root director
 * receives the final remainder so every individual betting line is conserved.
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
        $remaining = round($profit, 2);
        $last = count($leafToRoot) - 1;
        $allocations = [];

        foreach ($leafToRoot as $index => $node) {
            $incoming = $remaining;
            $isRoot = $index === $last || (int)($node['parent_id'] ?? 0) === 0;
            $rate = $isRoot ? 100.0 : max(0.0, min($rateCap, (float)($node['share_rate'] ?? 0.0)));
            $amount = $isRoot ? $remaining : round($remaining * $rate / 100, 2);
            $remaining = round($remaining - $amount, 2);
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
