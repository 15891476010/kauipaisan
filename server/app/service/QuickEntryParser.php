<?php
declare(strict_types=1);

namespace app\service;

final class QuickEntryParser
{
    /** @return array<int, array<string, mixed>> */
    public function parse(string $text, string $lottery): array
    {
        $text = mb_substr($text, 0, 10000);
        $lines = preg_split('/\r?\n/', trim($text)) ?: [];
        $result = [];
        $lineId = 1;
        $lineCount = count($lines);

        for ($index = 0; $index < $lineCount; $index++) {
            $rawOriginal = trim((string) $lines[$index]);
            if ($rawOriginal === '') continue;

            // A pasted ticket may put the lottery prefix on its own line:
            // 福\n百...十...个...各8元. Attach it to the following position block.
            if (preg_match('/^(福|体|福体)$/u', $this->normalize($rawOriginal)) === 1 && isset($lines[$index + 1])) {
                $next = trim((string) $lines[$index + 1]);
                if (preg_match('/(?:百|十|个)\s*[0-9]/u', $this->normalize($next)) === 1) {
                    $rawOriginal .= ' ' . $next;
                    $index++;
                }
            }

            // Position bets are often pasted as three visual lines (百/十/个).
            // Merge the block so amount extraction and Cartesian expansion see one sentence.
            if (preg_match('/(?:百|十|个)\s*[0-9]/u', $this->normalize($rawOriginal)) === 1) {
                $positionLines = [$rawOriginal];
                $hasAmount = preg_match('/(?:各|每|个|打|下)\s*\d+(?:\.\d+)?\s*(?:元|米|块|角|毛|倍)?/u', $rawOriginal) === 1;
                while (!$hasAmount && ++$index < $lineCount) {
                    $next = trim((string) $lines[$index]);
                    if ($next === '') continue;
                    $isPosition = preg_match('/(?:百|十|个)\s*[0-9]/u', $this->normalize($next)) === 1;
                    $isAmount = preg_match('/(?:各|每|个|打|下)\s*\d+(?:\.\d+)?\s*(?:元|米|块|角|毛|倍)?/u', $next) === 1;
                    if (!$isPosition && !$isAmount) { $index--; break; }
                    $positionLines[] = $next;
                    $hasAmount = $isAmount;
                }
                $rawOriginal = implode(' ', $positionLines);
            }

            $result[] = $this->parseLine($rawOriginal, $lottery, $lineId++);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function parseLine(string $rawOriginal, string $lottery, int $lineId): array
    {
        $raw = $this->normalize($rawOriginal);
        [$working, $declaredTotal] = $this->extractDeclaredTotal($raw);
        [$numberSource, $unitAmount, $perUnit] = $this->extractUnitAmount($working);
        $category = $this->category($raw, $lottery);
        $categoryCount = $category === '福体' ? 2 : 1;

        $position = $this->parsePosition($numberSource);
        if ($position !== null) {
            $numbers = $position['numbers'];
            $count = $position['count'] * $categoryCount;
            return $this->successOrAmountFailure(
                $lineId,
                $rawOriginal,
                $numbers,
                $category,
                $count,
                $unitAmount,
                $perUnit,
                $declaredTotal
            );
        }

        $numbers = $this->standaloneNumbers($numberSource);
        if (preg_match('/豹子全包/u', $raw) === 1 && $unitAmount > 0) {
            $numbers = array_map(static fn(int $digit): string => (string)$digit.$digit.$digit, range(0, 9));
            return $this->successOrAmountFailure($lineId, $rawOriginal, $numbers, $category, count($numbers), $unitAmount, false, $declaredTotal);
        }
        $hasPlay = preg_match('/直|组|胆|拖|跨|和|单双|大小|飞|定位|复式|豹子|包|转/u', $raw) === 1;
        if ($numbers === []) {
            return $this->failure($lineId, $rawOriginal, '未识别到有效号码');
        }
        if (!$hasPlay) {
            return $this->failure($lineId, $rawOriginal, '未识别到玩法', $numbers);
        }
        if (count($numbers) > 1000) {
            return $this->failure($lineId, $rawOriginal, '单行号码数量超过限制', $numbers);
        }

        return $this->successOrAmountFailure(
            $lineId,
            $rawOriginal,
            $numbers,
            $category,
            count($numbers) * $categoryCount,
            $unitAmount,
            $perUnit,
            $declaredTotal
        );
    }

    /** @return array{0: string, 1: ?float} */
    private function extractDeclaredTotal(string $raw): array
    {
        if (!preg_match('/(?:共|合计|总计)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛)?\s*$/u', $raw, $match, PREG_OFFSET_CAPTURE)) {
            return [$raw, null];
        }

        $amount = $this->withUnit((float) $match[1][0], (string) ($match[2][0] ?? ''));
        $working = trim(substr($raw, 0, (int) $match[0][1]));
        return [$working, $amount];
    }

    /** @return array{0: string, 1: float, 2: bool} */
    private function extractUnitAmount(string $raw): array
    {
        if (!preg_match_all('/(?:各|每|个|打|下)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?/u', $raw, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            // Whole-ticket amounts are also commonly written directly after a
            // play name, e.g. “豹子全包1500元”. Treat that as a total amount.
            if (preg_match('/(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)\s*$/u', $raw, $total, PREG_OFFSET_CAPTURE)) {
                $offset = (int) $total[0][1];
                $length = strlen((string) $total[0][0]);
                return [trim(substr($raw, 0, $offset) . ' ' . substr($raw, $offset + $length)), $this->withUnit((float) $total[1][0], (string) ($total[2][0] ?? '')), false];
            }
            return [$raw, 0.0, false];
        }

        $match = $matches[array_key_last($matches)];
        $lead = mb_substr((string) $match[0][0], 0, 1);
        $amount = $this->withUnit((float) $match[1][0], (string) ($match[2][0] ?? ''));
        $offset = (int) $match[0][1];
        $length = strlen((string) $match[0][0]);
        $numberSource = substr($raw, 0, $offset) . ' ' . substr($raw, $offset + $length);
        return [$numberSource, $amount, in_array($lead, ['各', '每', '个'], true)];
    }

    private function withUnit(float $amount, string $unit): float
    {
        return in_array($unit, ['角', '毛'], true) ? $amount / 10 : $amount;
    }

    /** @return ?array{numbers: array<int, string>, count: int} */
    private function parsePosition(string $raw): ?array
    {
        if (!preg_match('/百\s*([0-9]+)\s*十\s*([0-9]+)\s*个\s*([0-9]+)/u', $raw, $match)) {
            return null;
        }

        $hundreds = $this->uniqueDigits($match[1]);
        $tens = $this->uniqueDigits($match[2]);
        $ones = $this->uniqueDigits($match[3]);
        if ($hundreds === [] || $tens === [] || $ones === []) {
            return null;
        }

        $numbers = [];
        foreach ($hundreds as $hundred) {
            foreach ($tens as $ten) {
                foreach ($ones as $one) {
                    $numbers[] = $hundred . $ten . $one;
                }
            }
        }

        return ['numbers' => $numbers, 'count' => count($numbers)];
    }

    /** @return array<int, string> */
    private function uniqueDigits(string $value): array
    {
        $digits = str_split($value);
        return array_values(array_unique($digits));
    }

    /** @return array<int, string> */
    private function standaloneNumbers(string $raw): array
    {
        preg_match_all('/(?<!\d)(\d{1,3})(?!\d)/', $raw, $matches);
        $numbers = [];
        foreach (($matches[1] ?? []) as $number) {
            $number = str_pad((string) $number, 3, '0', STR_PAD_LEFT);
            if (!in_array($number, $numbers, true)) {
                $numbers[] = $number;
            }
        }
        return $numbers;
    }

    /**
     * @param array<int, string> $numbers
     * @return array<string, mixed>
     */
    private function successOrAmountFailure(
        int $lineId,
        string $rawOriginal,
        array $numbers,
        string $category,
        int $count,
        float $unitAmount,
        bool $perUnit,
        ?float $declaredTotal
    ): array {
        if ($declaredTotal === null && $unitAmount <= 0) {
            return $this->failure($lineId, $rawOriginal, '未识别到有效金额', $numbers);
        }

        $calculated = $perUnit ? $unitAmount * $count : $unitAmount;
        $totalAmount = $declaredTotal ?? $calculated;
        if ($declaredTotal !== null && $unitAmount > 0 && abs($declaredTotal - $calculated) > 0.001) {
            return $this->failure($lineId, $rawOriginal, '句末总金额与识别笔数、单注金额不一致', $numbers);
        }

        return [
            'id' => $lineId,
            'raw_text' => $rawOriginal,
            'status' => 'success',
            'reason' => null,
            'number_text' => implode(' ', $numbers),
            'category' => $category,
            'amount' => number_format($totalAmount, 2, '.', ''),
            'count' => $count,
        ];
    }

    /**
     * @param array<int, string> $numbers
     * @return array<string, mixed>
     */
    private function failure(int $lineId, string $rawOriginal, string $reason, array $numbers = []): array
    {
        return [
            'id' => $lineId,
            'raw_text' => $rawOriginal,
            'status' => 'failed',
            'reason' => $reason,
            'number_text' => implode(' ', $numbers),
            'category' => null,
            'amount' => '0.00',
            'count' => 0,
        ];
    }

    private function category(string $raw, string $lottery): string
    {
        $hasFu = str_contains($raw, '福');
        $hasTi = str_contains($raw, '体');
        if ($hasFu && $hasTi) {
            return '福体';
        }
        return $hasTi || $lottery === '排列三' ? '体' : '福';
    }

    private function normalize(string $text): string
    {
        $digits = ['零' => '0', '〇' => '0', '一' => '1', '壹' => '1', '二' => '2', '两' => '2', '贰' => '2', '三' => '3', '叁' => '3', '四' => '4', '肆' => '4', '五' => '5', '伍' => '5', '六' => '6', '陆' => '6', '七' => '7', '柒' => '7', '八' => '8', '捌' => '8', '九' => '9', '玖' => '9'];
        $text = strtr($text, ['褔' => '福', '陪' => '倍', '夸' => '跨', '垮' => '跨', '胯' => '跨', '挎' => '跨', '托' => '拖', '粘' => '沾', '黏' => '沾', '买' => ' ', '快' => '块']);
        $protected = ['组六' => '组__LIU__', '组三' => '组__SAN__', '一码' => '__YI__码', '二码' => '__ER__码', '三码' => '__SAN__码', '四码' => '__SI__码'];
        $text = strtr($text, $protected);
        $text = preg_replace_callback('/[零〇一二两三四五六七八九壹贰叁肆伍陆柒捌玖]/u', static fn(array $match): string => $digits[$match[0]] ?? $match[0], $text) ?? $text;
        return strtr($text, ['组__LIU__' => '组六', '组__SAN__' => '组三', '__YI__' => '一', '__ER__' => '二', '__SAN__' => '三', '__SI__' => '四']);
    }
}
