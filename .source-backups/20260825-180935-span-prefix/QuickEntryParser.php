<?php
declare(strict_types=1);

namespace app\service;

final class QuickEntryParser
{
    private QuickEntryRules $rules;

    public function __construct(?QuickEntryRules $rules = null)
    {
        $this->rules = $rules ?? new QuickEntryRules();
    }

    /** @return array<int, array<string, mixed>> */
    public function parse(string $text, string $lottery, float $unitStake = 2.0): array
    {
        $unitStake = $unitStake > 0 ? $unitStake : QuickEntryRules::DEFAULT_UNIT_STAKE;
        $text = mb_substr($text, 0, QuickEntryRules::MAX_TEXT_LENGTH);
        $inputOriginal = $text;
        $grandTotal = null;
        $normalizedInput = $this->rules->normalize($text);
        if (preg_match($this->rules->pattern('overall_total'), $normalizedInput, $grandMatch)) {
            $grandTotal = $this->rules->amountWithUnit((float)$grandMatch[1], (string)($grandMatch[2] ?? ''), $unitStake);
            $text = preg_replace($this->rules->pattern('overall_total_raw'), '', $text) ?? $text;
        }
        $lines = preg_split('/\r?\n/', trim($text)) ?: [];
        $result = [];
        $lineId = 1;
        $lineCount = count($lines);

        // A pasted direct ticket may use one physical line per group of
        // numbers and put the shared stake/play suffix on the last line,
        // e.g. "...\n999福430注直30". Those line breaks are meaningful in
        // the result table: each physical line is one result row. Detect
        // every explicit "N注" segment before the generic wrapped-ticket
        // merger. Scanning segments (instead of treating the whole input as
        // one batch) also keeps two marked tickets and ordinary lines in the
        // same paste independent.
        $batchSegments = $this->findNumberLineBatchSegments($lines, $lottery, $unitStake);

        for ($index = 0; $index < $lineCount; $index++) {
            if (isset($batchSegments[$index])) {
                foreach ($batchSegments[$index]['rows'] as $row) {
                    $row['id'] = $lineId++;
                    if (($row['status'] ?? '') === 'success') {
                        $row['ast'] = $this->astFor($row, $lottery);
                    }
                    $result[] = $row;
                }
                $index = $batchSegments[$index]['end'];
                continue;
            }
            $rawOriginal = trim((string) $lines[$index]);
            if ($rawOriginal === '') continue;

            // Tickets often wrap a long number list across visual lines and
            // put the play/amount only on the final line.
            if ($this->isStandaloneNumberLine($this->rules->normalize($rawOriginal))) {
                $block = [$rawOriginal];
                for ($cursor = $index + 1; $cursor < $lineCount; $cursor++) {
                    $next = trim((string)$lines[$cursor]);
                    if ($next === '') break;
                    $normalizedNext = $this->rules->normalize($next);
                    if ($this->isStandaloneNumberLine($normalizedNext)) {
                        $block[] = $next;
                        continue;
                    }
                    if (preg_match($this->rules->pattern('number_block_continuation'), $normalizedNext) === 1) {
                        $block[] = $next;
                        $rawOriginal = implode(' ', $block);
                        $index = $cursor;
                    }
                    break;
                }
            }

            // A pasted ticket may put the lottery prefix on its own line:
            // 福\n百...十...个...各8元. Attach it to the following position block.
            if (preg_match($this->rules->pattern('lottery_only'), $this->rules->normalize($rawOriginal)) === 1 && isset($lines[$index + 1])) {
                $next = trim((string) $lines[$index + 1]);
                $normalizedNext = $this->rules->normalize($next);
                if (preg_match($this->rules->pattern('position_segment'), $normalizedNext) === 1
                    || preg_match($this->rules->pattern('lottery_following_bet'), $normalizedNext) === 1) {
                    $rawOriginal .= ' ' . $next;
                    $index++;
                }
            }

            // Position bets are often pasted as three visual lines (百/十/个).
            // Merge the block so amount extraction and Cartesian expansion see one sentence.
            if (preg_match($this->rules->pattern('position_segment'), $this->rules->normalize($rawOriginal)) === 1) {
                $positionLines = [$rawOriginal];
                $hasAmount = preg_match($this->rules->pattern('per_unit_amount'), $rawOriginal) === 1;
                while (!$hasAmount && ++$index < $lineCount) {
                    $next = trim((string) $lines[$index]);
                    if ($next === '') continue;
                    $isPosition = preg_match($this->rules->pattern('position_segment'), $this->rules->normalize($next)) === 1;
                    $isAmount = preg_match($this->rules->pattern('per_unit_amount'), $next) === 1;
                    if (!$isPosition && !$isAmount) { $index--; break; }
                    $positionLines[] = $next;
                    $hasAmount = $isAmount;
                }
                $rawOriginal = implode(' ', $positionLines);
            }

            $parsed = $this->parseLine($rawOriginal, $lottery, $lineId, $unitStake);
            if (isset($parsed[0]) && is_array($parsed[0])) {
                foreach ($parsed as $row) {
                    $row['id'] = $lineId++;
                    if (($row['status']??'')==='success') $row['ast']=$this->astFor($row,$lottery);
                    $result[] = $row;
                }
            } else {
                $parsed['id'] = $lineId++;
                if (($parsed['status']??'')==='success') $parsed['ast']=$this->astFor($parsed,$lottery);
                $result[] = $parsed;
            }
        }

        if ($grandTotal !== null) {
            $calculated = array_sum(array_map(
                static fn(array $row): float => ($row['status'] ?? '') === 'success' ? (float)($row['amount'] ?? 0) : 0.0,
                $result
            ));
            $hasFailure = count(array_filter($result, static fn(array $row): bool => ($row['status'] ?? '') !== 'success')) > 0;
            if ($hasFailure || abs($grandTotal - $calculated) > 0.001) {
                return [$this->failure(1, $inputOriginal, '整张总金额与识别金额不一致')];
            }
        }

        return $result;
    }

    /**
     * Find explicit physical-line batches embedded in a larger paste.
     *
     * A marker is an N注 + play + amount suffix. The marker may be attached
     * to the final number line or be on its own line. Number-only lines
     * immediately before it belong to that batch until a blank/non-number
     * line or another marker is reached.
     *
     * @param array<int, string> $lines
     * @return array<int, array{end: int, rows: array<int, array<string, mixed>>}>
     */
    private function findNumberLineBatchSegments(array $lines, string $lottery, float $unitStake): array
    {
        $segments = [];
        $claimedUntil = -1;
        $lineCount = count($lines);
        for ($suffixIndex = 0; $suffixIndex < $lineCount; $suffixIndex++) {
            if ($suffixIndex <= $claimedUntil) continue;
            $suffix = $this->batchSuffixInfo((string)$lines[$suffixIndex]);
            if ($suffix === null) continue;

            $numberEnd = $suffix['prefix'] !== '' ? $suffixIndex : $suffixIndex - 1;
            if ($numberEnd < 0) continue;
            if ($suffix['prefix'] !== '' && !$this->isStandaloneNumberLine($suffix['prefix'])) continue;
            if ($suffix['prefix'] === '' && !$this->isStandaloneNumberLine($this->rules->normalize((string)$lines[$numberEnd]))) continue;

            $start = $numberEnd;
            while ($start > 0) {
                $previous = trim((string)$lines[$start - 1]);
                if ($previous === '' || !$this->isStandaloneNumberLine($this->rules->normalize($previous))) break;
                $start--;
            }

            $batchRows = $this->parseNumberLineBatch(
                array_slice($lines, $start, $suffixIndex - $start + 1),
                $lottery,
                $unitStake
            );
            if ($batchRows === null) continue;
            $segments[$start] = ['end' => $suffixIndex, 'rows' => $batchRows];
            $claimedUntil = $suffixIndex;
        }
        return $segments;
    }

    /** @return ?array{normalized: string, prefix: string} */
    private function batchSuffixInfo(string $line): ?array
    {
        $normalized = trim($this->rules->normalize($line));
        // normalize() already maps aliases such as “直选” to “直” and
        // Chinese numerals in the declaration to Arabic digits.
        $pattern = '/(?:(福体|福|体)\s*)?(\d+)\s*注\s*(直|单|组三|组六|组)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?\s*$/u';
        if (preg_match($pattern, $normalized, $match, PREG_OFFSET_CAPTURE) !== 1) return null;
        $offset = (int)$match[0][1];
        return ['normalized' => $normalized, 'prefix' => trim(substr($normalized, 0, $offset))];
    }

    /**
     * Parse a multi-line number list whose final line declares the total
     * number of stakes and a shared play/amount suffix.
     *
     * @param array<int, string> $lines
     * @return ?array<int, array<string, mixed>>
     */
    private function parseNumberLineBatch(array $lines, string $lottery, float $unitStake): ?array
    {
        $entries = [];
        foreach ($lines as $line) {
            $value = trim((string)$line);
            if ($value !== '') {
                $entries[] = $value;
            }
        }
        if (count($entries) < 2) {
            return null;
        }

        $lastIndex = count($entries) - 1;
        $last = $entries[$lastIndex];
        $suffix = $this->batchSuffixInfo($last);
        if ($suffix === null) return null;

        $lastNumbersText = $suffix['prefix'];
        $numberLastIndex = $lastIndex;
        $suffixOnSeparateLine = $lastNumbersText === '';
        if ($suffixOnSeparateLine) {
            // Some editors wrap the shared suffix onto its own physical line.
            // It still belongs to the preceding number line and must not cause
            // the whole ticket to fall back to the generic wrapped merger.
            if ($lastIndex < 1) {
                return null;
            }
            $numberLastIndex = $lastIndex - 1;
            $lastNumbersText = trim($entries[$numberLastIndex]);
        }
        if (!$this->isStandaloneNumberLine($this->rules->normalize($lastNumbersText))) {
            return null;
        }

        $numberLines = [];
        $batchNumbers = [];
        $batchOccurrences = [];
        $batchNumberFrequency = [];
        $seenBatchNumbers = [];
        $totalStakeCount = 0;
        $numberEntries = array_slice($entries, 0, $numberLastIndex + 1);
        foreach ($numberEntries as $index => $entry) {
            $numberText = $index === $numberLastIndex ? $lastNumbersText : $entry;
            if (!$this->isStandaloneNumberLine($this->rules->normalize($numberText))) {
                return null;
            }
            $numbers = $this->standaloneNumbers($this->rules->normalize($numberText));
            if ($numbers === []) {
                return null;
            }
            $rawLine = $entry;
            if ($suffixOnSeparateLine && $index === $numberLastIndex) {
                $rawLine .= ' ' . $last;
            }
            $numberLines[] = ['raw' => $rawLine, 'number_raw' => $numberText, 'numbers' => $numbers];
            $totalStakeCount += count($numbers);
            foreach ($numbers as $number) {
                $batchOccurrences[] = $number;
                $batchNumberFrequency[$number] = ($batchNumberFrequency[$number] ?? 0) + 1;
                if (!isset($seenBatchNumbers[$number])) {
                    $seenBatchNumbers[$number] = true;
                    $batchNumbers[] = $number;
                }
            }
        }

        // The explicit "N注" suffix is the batch marker. Its count is useful
        // as a consistency hint, but pasted/replaced number lists frequently
        // leave the old N behind. Keep the physical rows and calculate the
        // effective batch from the actual numbers instead of silently merging
        // the ticket into one generic wrapped row.
        $normalizedLast = $suffix['normalized'];
        preg_match('/(?:(福体|福|体)\s*)?(\d+)\s*注\s*(直|单|组三|组六|组)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?\s*$/u', $normalizedLast, $suffixValues);
        $declaredStakeCount = (int)($suffixValues[2] ?? 0);
        $countMismatch = $declaredStakeCount !== $totalStakeCount;

        $category = trim((string)($suffixValues[1] ?? ''));
        if ($category === '') {
            $category = $lottery === '排列三' ? '体' : '福';
        }
        $play = $this->rules->normalizePlay((string)($suffixValues[3] ?? '直'));
        $amount = (string)($suffixValues[4] ?? '0');
        $unit = (string)($suffixValues[5] ?? '');

        $rows = [];
        foreach ($numberLines as $numberLine) {
            $localCount = count($numberLine['numbers']);
            $annotation = $category.$localCount.'注'.$play.$amount.$unit;
            $parseText = rtrim((string)$numberLine['number_raw']).$annotation;
            $parsed = $this->parseLine($parseText, $lottery, 1, $unitStake);
            $parsedRows = isset($parsed[0]) && is_array($parsed[0]) ? $parsed : [$parsed];
            foreach ($parsedRows as $row) {
                $row['raw_text'] = $numberLine['raw'];
                $row['parse_text'] = $parseText;
                $rows[] = $row;
            }
        }

        $batchId = 'number-'.substr(hash('sha256', implode("\n", $entries)), 0, 16);
        $batchSize = count($rows);
        $batchCategoryCount = $category === '福体' ? 2 : 1;
        $batchDuplicateNumbers = array_map(
            static fn($number): string => str_pad((string)$number, 3, '0', STR_PAD_LEFT),
            array_keys(array_filter(
                $batchNumberFrequency,
                static fn(int $frequency): bool => $frequency > 1
            ))
        );
        $batchAmount = number_format(array_sum(array_map(
            static fn(array $row): float => ($row['status'] ?? '') === 'success' ? (float)($row['amount'] ?? 0) : 0.0,
            $rows
        )), 2, '.', '');
        $batchMergedText = implode('，', $batchOccurrences).$category.$totalStakeCount.'注'.$play.$amount.$unit;
        foreach ($rows as $batchIndex => &$row) {
            $row['batch_id'] = $batchId;
            $row['batch_index'] = $batchIndex + 1;
            $row['batch_size'] = $batchSize;
            $row['batch_end'] = $batchIndex === $batchSize - 1;
            if ($row['batch_end']) {
                $row['batch_count'] = count($batchNumbers) * $batchCategoryCount;
                $row['batch_stake_count'] = $totalStakeCount * $batchCategoryCount;
                $row['batch_amount'] = $batchAmount;
                $row['batch_number_text'] = implode(' ', $batchNumbers);
                $row['batch_occurrence_text'] = implode(' ', $batchOccurrences);
                $row['batch_merged_text'] = $batchMergedText;
                $row['batch_has_duplicates'] = $batchDuplicateNumbers !== [];
                $row['batch_duplicate_numbers'] = $batchDuplicateNumbers;
                $row['batch_declared_stake_count'] = $declaredStakeCount;
                $row['batch_count_mismatch'] = $countMismatch;
            }
        }
        unset($row);

        return $rows;
    }

    public function formatText(string $text): string
    {
        return $this->rules->formatText($text);
    }

    /** @return array<string,mixed> */
    private function astFor(array $row,string $fallbackLottery): array
    {
        $category=(string)($row['category']??'');
        $lotteries=match($category){'福'=>['FC3D'],'体'=>['PL3'],'福体'=>['FC3D','PL3'],default=>[$fallbackLottery==='排列三'?'PL3':'FC3D']};
        $source=$this->rules->normalize((string)($row['settlement_text']??$row['raw_text']??''));
        $playType=(string)($row['play_type']??'');
        preg_match_all('/[百十个]/u',$source,$positionMatches);
        $markers=array_values(array_unique($positionMatches[0]??[]));
        $play=match(true){
            str_starts_with($playType,'组三')||(str_contains($source,'组三')&&str_contains($source,'胆拖'))=>'Z3',
            str_starts_with($playType,'组六')||(str_contains($source,'组六')&&str_contains($source,'胆拖'))=>'Z6',
            str_starts_with($playType,'和值')||str_starts_with($playType,'和')=>'HZ',
            str_starts_with($playType,'跨度')=>'KD',
            count($markers)===1=>'1D',
            count($markers)===2=>'2D',
            $playType==='直'||count($markers)>=3=>'ZX',
            default=>$playType,
        };
        $ast=['lottery'=>$lotteries,'play'=>$play,'amount'=>(float)($row['amount']??0),'each'=>str_contains($source,'各')||str_contains($source,'每')];
        $numbers=preg_split('/\s+/',trim((string)($row['number_text']??'')))?:[];
        $numbers=array_values(array_filter($numbers,static fn(string $number):bool=>preg_match('/^\d{3}$/',$number)===1));
        if($numbers!==[]&&!($numbers===['000']&&$play!=='ZX'))$ast['numbers']=$numbers;
        if(preg_match('/(?<!\d)(\d{1,10})\s*(?:组三赖|组六赖|组三|组六|复式)[一二两三四五六七八九]码/u',$source,$package))$ast['package']=$package[1];
        if(preg_match('/胆(\d{1,2})拖(\d{2,9})/u',$source,$drag)){$ast['dan']=$drag[1];$ast['tuo']=$drag[2];}
        if(preg_match('/和值\s*(2[0-7]|1\d|\d)/u',$source,$sum))$ast['sum']=(int)$sum[1];
        if(preg_match('/跨度\s*([0-9])/u',$source,$span))$ast['span']=(int)$span[1];
        if($markers!==[]){$position=[];foreach($markers as $marker)if(preg_match('/'.$marker.'\s*([0-9]+)/u',$source,$value))$position[['百'=>'bai','十'=>'shi','个'=>'ge'][$marker]]=$value[1];if($position!==[])$ast['position']=$position;}
        if(preg_match('/(\d+(?:\.\d+)?)\s*倍/u',$source,$multiple))$ast['multiple']=(float)$multiple[1];
        return $ast;
    }

    /** @return array<string, mixed> */
    private function parseLine(string $rawOriginal, string $lottery, int $lineId, float $unitStake): array
    {
        $raw = $this->rules->normalize($rawOriginal);
        if (preg_match($this->rules->pattern('unsupported_play'),$raw,$unsupported)) {
            return $this->failure($lineId,$rawOriginal,'当前赔率目录未配置“'.$unsupported[0].'”玩法，已禁止下注');
        }
        [$working, $declaredTotal] = $this->extractDeclaredTotal($raw, $unitStake);
        $category = $this->rules->category($raw, $lottery);
        $outcomeBet = $this->parseOutcomeBet($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($outcomeBet !== null) return $outcomeBet;
        $outcomeSet = $this->parseOutcomeSet($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($outcomeSet !== null) return $outcomeSet;
        $catalogBet = $this->parseCatalogBet($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($catalogBet !== null) return $catalogBet;
        $dragBets = $this->parseDragBets($rawOriginal,$raw,$category,$lineId,$unitStake);
        if ($dragBets !== null) return $dragBets;
        $multiCodeBets = $this->parseMultiCodeBets($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($multiCodeBets !== null) return $multiCodeBets;
        $groupedDigitSet = $this->parseGroupedDigitSet($rawOriginal, $working, $category, $lineId, $declaredTotal, $unitStake);
        if ($groupedDigitSet !== null) return $groupedDigitSet;
        $explicitPlayBets = $this->parseExplicitPlayBets($rawOriginal, $working, $category, $lineId, $declaredTotal, $unitStake);
        if ($explicitPlayBets !== null) return $explicitPlayBets;
        [$numberSource, $unitAmount, $perUnit] = $this->extractUnitAmount($working, $unitStake);
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

        $partialPosition = $this->parsePartialPosition($numberSource);
        if ($partialPosition !== null) {
            return $this->successOrAmountFailure($lineId,$rawOriginal,$partialPosition['numbers'],$category,$partialPosition['count']*$categoryCount,$unitAmount,$perUnit,$declaredTotal);
        }

        $singlePosition = $this->parseSinglePosition($numberSource);
        if ($singlePosition !== null) {
            return $this->successOrAmountFailure(
                $lineId,
                $rawOriginal,
                [$singlePosition],
                $category,
                1,
                $unitAmount,
                $perUnit,
                $declaredTotal
            );
        }

        $numbers = $this->standaloneNumbers($numberSource);
        if (preg_match($this->rules->pattern('leopard_all'), $raw) === 1 && $unitAmount > 0) {
            $numbers = array_map(static fn(int $digit): string => (string)$digit.$digit.$digit, range(0, 9));
            return $this->successOrAmountFailure($lineId, $rawOriginal, $numbers, $category, count($numbers), $unitAmount, false, $declaredTotal);
        }
        $hasPlay = preg_match($this->rules->pattern('any_play'), $raw) === 1;
        if ($numbers === []) {
            return $this->failure($lineId, $rawOriginal, '未识别到有效号码');
        }
        if (!$hasPlay) {
            $implicitSource=trim(str_replace(['福体','福','体'],' ',$raw));
            if(count($numbers)!==1||preg_match('/^\d{3}(?:\s+\d+(?:\.\d+)?\s*倍)?$/',$implicitSource)!==1)return $this->failure($lineId,$rawOriginal,'未识别到玩法', $numbers);
            $directAmount=$unitAmount>0?$unitAmount:$unitStake;
            $row=$this->successOrAmountFailure($lineId,$rawOriginal,$numbers,$category,$categoryCount,$directAmount,true,$declaredTotal);
            $row['play_type']='直';
            $row['settlement_text']=$numbers[0].' 直各'.number_format($directAmount,2,'.','').'元 '.$category;
            return $row;
        }
        if (count($numbers) > QuickEntryRules::MAX_NUMBERS_PER_LINE) {
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
    private function extractDeclaredTotal(string $raw, float $unitStake): array
    {
        if (!preg_match($this->rules->pattern('declared_total'), $raw, $match, PREG_OFFSET_CAPTURE)) {
            return [$raw, null];
        }

        $amount = $this->rules->amountWithUnit((float) $match[1][0], (string) ($match[2][0] ?? ''), $unitStake);
        $working = trim(substr($raw, 0, (int) $match[0][1]));
        return [$working, $amount];
    }

    /** @return ?array<int, array<string, mixed>> */
    private function parseExplicitPlayBets(string $rawOriginal, string $working, string $category, int $lineId, ?float $declaredTotal, float $unitStake): ?array
    {
        $useStakeForBareAmount = preg_match('/(?<!\d)\d+\s*注(?=\s*(?:直|单|组|组三|组六|胆拖|和值|跨度|豹子|对子|双飞|包选|全包|飞|定位胆|定位|复式))/u', $working) === 1;
        if ($useStakeForBareAmount) {
            $working = preg_replace('/(?<!\d)\d+\s*注(?=\s*(?:直|单|组|组三|组六|胆拖|和值|跨度|豹子|对子|双飞|包选|全包|飞|定位胆|定位|复式))/u', ' ', $working) ?? $working;
        }
        $specs = [];
        $remove = [];
        $combinedMatched = preg_match_all($this->rules->pattern('combined_direct_group'), $working, $combined, PREG_SET_ORDER) ?: 0;
        if ($combinedMatched === 0) {
            $combinedMatched = preg_match_all($this->rules->pattern('combined_direct_group_short'), $working, $combined, PREG_SET_ORDER) ?: 0;
        }
        if ($combinedMatched > 0) {
            foreach ($combined as $match) {
                $specs[] = ['play' => '直', 'unit_amount' => (float)$match[1] * $unitStake];
                $specs[] = ['play' => '组', 'unit_amount' => (float)$match[3] * $unitStake];
                $remove[] = $match[0];
            }
        } elseif (preg_match_all($this->rules->pattern('multiplier_before_play'), $working, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $specs[] = ['play' => $this->rules->normalizePlay($match[2]), 'unit_amount' => (float)$match[1] * $unitStake];
                $remove[] = $match[0];
            }
        }
        if ($combinedMatched === 0 && preg_match_all($this->rules->pattern('amount_after_play'), $working, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $unit=trim((string)($match[3]??''));
                $unitAmount=$unit==='倍'
                    ? (float)$match[2] * $unitStake
                    : ($unit === '' && $useStakeForBareAmount
                        ? (float)$match[2] * $unitStake
                        : $this->rules->playAmount((float)$match[2], $unit, $unitStake));
                $specs[] = ['play' => $this->rules->normalizePlay($match[1]), 'unit_amount' => $unitAmount];
                $remove[] = $match[0];
            }
        }
        if ($specs === [] && preg_match($this->rules->pattern('trailing_multiplier'), $working, $match)) {
            $play = $this->rules->trailingPlay($working);
            if ($play !== null) {
                $specs[] = ['play' => $play, 'unit_amount' => (float)$match[1] * $unitStake];
                $remove[] = $match[0];
            }
        }
        if ($specs === [] && preg_match($this->rules->pattern('trailing_short_multiplier'), $working, $match)) {
            $specs[] = [
                'play' => $this->rules->normalizePlay($match[2]),
                'unit_amount' => (float)$match[1] * $unitStake,
            ];
            $remove[] = $match[0];
        }
        if ($specs === []) return null;

        $numberSource = str_replace($remove, ' ', $working);
        $numbers = $this->standaloneNumbers($numberSource);
        if ($numbers === []) return [$this->failure($lineId, $rawOriginal, '未识别到有效号码')];
        if (count($numbers) > QuickEntryRules::MAX_NUMBERS_PER_LINE) return [$this->failure($lineId, $rawOriginal, '单行号码数量超过限制', $numbers)];

        $rows = [];
        $categoryCount = $category === '福体' ? 2 : 1;
        foreach ($specs as $spec) {
            if($spec['play']==='组')$groups=$this->groupNumbersByPlay($numbers);
            elseif(in_array($spec['play'],['组三','组六'],true)){$classified=$this->groupNumbersByPlay($numbers);$groups=[$spec['play']=>$classified[$spec['play']]??[]];}
            else $groups=['直'=>$numbers];
            foreach ($groups as $playType => $playNumbers) {
                if ($playNumbers === []) continue;
                $row = $this->successOrAmountFailure($lineId, $rawOriginal, $playNumbers, $category, count($playNumbers) * $categoryCount, (float)$spec['unit_amount'], true, null);
                $row['play_type'] = $playType;
                $row['settlement_text'] = implode(' ', $playNumbers).' '.$playType.'各'.number_format((float)$spec['unit_amount'], 2, '.', '').'元 '.$category;
                $rows[] = $row;
            }
        }
        $calculated = array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $rows));
        if ($declaredTotal !== null && abs($declaredTotal - $calculated) > 0.001) {
            return [$this->failure($lineId, $rawOriginal, '句末总金额与玩法金额不一致', $numbers)];
        }
        return $rows;
    }

    /** @return ?array<int, array<string, mixed>> */
    private function parseGroupedDigitSet(string $rawOriginal, string $working, string $category, int $lineId, ?float $declaredTotal, float $unitStake): ?array
    {
        if (!preg_match($this->rules->pattern('digit_set_head'), $working, $head)) return null;
        $digits = $this->uniqueDigits($head[1]);
        if (count($digits) < 3) return null;
        $playFirst = preg_match($this->rules->pattern('group_starts_with_play'), $head[2]) === 1;
        if(!$playFirst&&preg_match('/^\s*\d{3}\s+\d{3}(?:\s+|$)/u',(string)$head[2])===1)return null;
        if ($playFirst) {
            if (!preg_match_all($this->rules->pattern('group_play_first'), $head[2], $matches, PREG_SET_ORDER)) return null;
        } else {
            if (!preg_match_all($this->rules->pattern('group_amount_first'), $head[2], $matches, PREG_SET_ORDER)) return null;
        }
        $rows = [];
        foreach ($matches as $match) {
            if ($playFirst) {
                $play = $this->rules->normalizeGroupPlay((string)$match[1]);
                $perUnit = trim((string)($match[2] ?? '')) !== '';
                $rawAmount = (float)$match[3];
                $unit = (string)($match[4] ?? '');
            } else {
                $play = $this->rules->normalizeGroupPlay((string)$match[3]);
                $perUnit = false;
                $rawAmount = (float)$match[1];
                $unit = (string)($match[2] ?? '');
            }
            $categoryCount = $category === '福体' ? 2 : 1;
            $unitAmount = $this->rules->amountWithUnit($rawAmount, $unit, $unitStake);
            $rawSelection=(string)$head[1];
            $displayNumberText = '';
            if(strlen($rawSelection)===3&&in_array($play,['组三','组六'],true)){
                $uniqueCount=count($digits);
                if(($play==='组三'&&$uniqueCount===2)||($play==='组六'&&$uniqueCount===3)){$playType=$play;$numberText=$rawSelection;}
                elseif($play==='组三'&&$uniqueCount===3){$identity=$this->rules->inferredCatalogPlay($play,3);$playType=$identity['name'];$numberText=implode(' ',$this->groupThreeCombinations($digits));}
                else return [$this->failure($lineId,$rawOriginal,'号码形态与玩法不一致')];
            } else {
                $identity = $this->rules->inferredCatalogPlay($play, count($digits));
                if ($identity === null) return [$this->failure($lineId,$rawOriginal,'所选数字数量与玩法不一致')];
                $playType=$identity['name'];
                $numberText=match($play){
                    '组三'=>implode(' ',$this->groupThreeCombinations($digits)),
                    '组六'=>implode(' ',$this->groupSixCombinations($digits)),
                    default=>'000',
                };
                if ($play === '复式') $displayNumberText = '复'.$rawSelection;
            }
            $amount = $perUnit ? $unitAmount * $categoryCount : $unitAmount;
            $rows[] = [
                'id' => $lineId,
                'raw_text' => $rawOriginal,
                'status' => 'success',
                'reason' => null,
                'number_text' => $numberText,
                'category' => $category,
                'amount' => number_format($amount, 2, '.', ''),
                'count' => $categoryCount,
                'play_type' => $playType,
                'settlement_text' => implode('',$digits).' '.$playType.($perUnit ? '各'.number_format($unitAmount, 2, '.', '').'元' : '').' '.$category,
            ];
            if ($displayNumberText !== '') $rows[array_key_last($rows)]['display_number_text'] = $displayNumberText;
        }
        if ($rows === []) return null;
        $calculated = array_sum(array_map(static fn(array $row):float => (float)$row['amount'], $rows));
        if ($declaredTotal !== null && abs($declaredTotal - $calculated) > 0.001) return [$this->failure($lineId, $rawOriginal, '句末总金额与组选金额不一致')];
        return $rows;
    }

    /** @return ?array<string, mixed> */
    private function parseOutcomeBet(string $rawOriginal, string $raw, string $category, int $lineId, float $unitStake): ?array
    {
        $playType = null;
        $amount = 0.0;
        $playTypes = [];
        if (preg_match($this->rules->pattern('size_parity_stake_bet'), $raw, $match)) {
            $playType = in_array($match[1], ['大', '小', '单', '双'], true) ? '和'.$match[1] : $match[1];
            $amount = $this->rules->playAmount((float)$match[2], '', $unitStake);
            $playTypes = [$playType];
        } elseif (preg_match($this->rules->pattern('size_parity_both_bet'), $raw, $match)) {
            $unit = (string)($match[2] ?? '');
            $amount = $unit === '注'
                ? $this->rules->playAmount((float)$match[1], '', $unitStake)
                : ($unit === '' ? $this->rules->playAmount((float)$match[1], '', $unitStake) : $this->rules->amountWithUnit((float)$match[1], $unit, $unitStake));
            $playTypes = ['和大', '和小'];
        }
        if (preg_match($this->rules->pattern('size_parity_bet'), $raw, $match)) {
            $playType = in_array($match[1], ['大', '小', '单', '双'], true) ? '和'.$match[1] : $match[1];
            $unit = (string)($match[3] ?? '');
            $amount = $unit === '' ? $this->rules->playAmount((float)$match[2], '', $unitStake) : $this->rules->amountWithUnit((float)$match[2], $unit, $unitStake);
            $playTypes = [$playType];
        } elseif (preg_match($this->rules->pattern('span_bet'), $raw, $match)) {
            $playType = '跨度'.$match[1];
            $amount = $this->rules->amountWithUnit((float)$match[2], (string)($match[3] ?? ''), $unitStake);
            $playTypes = [$playType];
        } elseif (preg_match($this->rules->pattern('sum_bet'), $raw, $match)) {
            $playType = '和值'.$match[1];
            $amount = $this->rules->amountWithUnit((float)$match[2], (string)($match[3] ?? ''), $unitStake);
            $playTypes = [$playType];
        } elseif (preg_match($this->rules->pattern('package_bet'), $raw, $match)) {
            $playType = $match[1];
            $amount = $this->rules->amountWithUnit((float)$match[2], (string)($match[3] ?? ''), $unitStake);
            $playTypes = [$playType];
        }
        if ($playTypes === []) return null;
        $categoryCount = $category === '福体' ? 2 : 1;
        return array_map(fn(string $type): array => [
            'id' => $lineId,
            'raw_text' => $rawOriginal,
            'status' => 'success',
            'reason' => null,
            'number_text' => '000',
            'category' => $category,
            'amount' => number_format($amount * $categoryCount, 2, '.', ''),
            'count' => $categoryCount,
            'play_type' => $type,
            'settlement_text' => $type.' '.$category,
        ], $playTypes);
    }

    /** @return ?array<int, array<string, mixed>> */
    private function parseOutcomeSet(string $rawOriginal,string $raw,string $category,int $lineId,float $unitStake): ?array
    {
        if (!preg_match($this->rules->pattern('outcome_set_suffix'),$raw,$match,PREG_OFFSET_CAPTURE)) return null;
        $kind=(string)$match[1][0];
        $amount=$this->rules->amountWithUnit((float)$match[2][0],(string)($match[3][0]??''),$unitStake);
        $selectionText=trim(substr($raw,0,(int)$match[0][1]));
        $selectionText=str_replace(['福体','福','体'],' ',$selectionText);
        $tokens=[];
        foreach (preg_split('/[\s,，、\-]+/u',trim($selectionText))?:[] as $token) {
            if ($token==='') continue;
            if (in_array($token,['大','小','单','双'],true)) { $tokens[]='和'.$token; continue; }
            if (!ctype_digit($token)) return [$this->failure($lineId,$rawOriginal,'和值或跨度选号格式不明确')];
            if (in_array($kind,['跨','跨度'],true)) { foreach(str_split($token) as $digit)$tokens[]='跨度'.$digit; }
            elseif (in_array($kind,['和','和值'],true) && (int)$token<=27) $tokens[]='和值'.(int)$token;
            else return [$this->failure($lineId,$rawOriginal,'和值或跨度选号超出范围')];
        }
        if ($tokens===[]) return [$this->failure($lineId,$rawOriginal,'未识别到和值或跨度选号')];
        $rows=[];$categoryCount=$category==='福体'?2:1;
        foreach(array_values(array_unique($tokens)) as $playType)$rows[]=['id'=>$lineId,'raw_text'=>$rawOriginal,'status'=>'success','reason'=>null,'number_text'=>'000','category'=>$category,'amount'=>number_format($amount*$categoryCount,2,'.',''),'count'=>$categoryCount,'play_type'=>$playType,'settlement_text'=>$playType.' '.$category];
        return $rows;
    }

    /** @return ?array<int, array<string, mixed>> */
    private function parseMultiCodeBets(string $rawOriginal,string $raw,string $category,int $lineId,float $unitStake): ?array
    {
        $specs=[];$remove=[];$selectionSourceOverride=null;
        // 兼容玩法写在号码前面的录入方式：福体组六组三 123456 各1米。
        if (preg_match($this->rules->pattern('multi_play_prefix_amount'),$raw,$prefix)) {
            preg_match_all($this->rules->pattern('multi_play_name'),(string)$prefix[1],$plays);
            foreach(array_unique($plays[0]??[]) as $play)$specs[]=['play'=>$play,'amount'=>$this->rules->amountWithUnit((float)$prefix[3],(string)($prefix[4]??''),$unitStake)];
            $selectionSourceOverride=(string)$prefix[2];
        } elseif (preg_match($this->rules->pattern('multi_play_shared_amount'),$raw,$shared)) {
            preg_match_all($this->rules->pattern('multi_play_name'),$shared[1],$plays);
            foreach(array_unique($plays[0]??[]) as $play)$specs[]=['play'=>$play,'amount'=>$this->rules->amountWithUnit((float)$shared[2],(string)($shared[3]??''),$unitStake)];
            $remove[]=$shared[0];
        } elseif (preg_match_all($this->rules->pattern('multi_play_amount'),$raw,$matches,PREG_SET_ORDER)) {
            foreach($matches as $match){$specs[]=['play'=>$match[1],'amount'=>$this->rules->amountWithUnit((float)$match[2],(string)($match[3]??''),$unitStake)];$remove[]=$match[0];}
        }
        if ($specs===[]) return null;
        $selectionSource=$selectionSourceOverride??str_replace($remove,' ',$raw);
        $selectionSource=str_replace(['福体','福','体'],' ',$selectionSource);
        preg_match_all($this->rules->pattern('digit_selection_set'),$selectionSource,$sets);
        $selections=[];
        foreach($sets[1]??[] as $set){$unique=implode('',$this->uniqueDigits($set));if(strlen($unique)>=2)$selections[$set]=['raw'=>$set,'unique'=>$unique];}
        $selections=array_values($selections);
        if($selections===[]) return [$this->failure($lineId,$rawOriginal,'未识别到多码选号')];
        $rows=[];$categoryCount=$category==='福体'?2:1;
        foreach($selections as $selection)foreach($specs as $spec){
            $rawSelection=$selection['raw'];$uniqueSelection=$selection['unique'];
            if(strlen($rawSelection)===3&&in_array($spec['play'],['组三','组六'],true)){
                $required=$spec['play']==='组三'?2:3;
                if(strlen($uniqueSelection)!==$required)return [$this->failure($lineId,$rawOriginal,'号码形态与玩法不一致')];
                $rows[]=['id'=>$lineId,'raw_text'=>$rawOriginal,'status'=>'success','reason'=>null,'number_text'=>$rawSelection,'category'=>$category,'amount'=>number_format($spec['amount']*$categoryCount,2,'.',''),'count'=>$categoryCount,'play_type'=>$spec['play'],'settlement_text'=>$rawSelection.' '.$spec['play'].' '.$category];
                continue;
            }
            $identity=$this->rules->inferredCatalogPlay($spec['play'],strlen($uniqueSelection));
            if($identity===null)return [$this->failure($lineId,$rawOriginal,'所选数字数量与玩法不一致')];
            $numberText=match($spec['play']){
                '组三'=>implode(' ',$this->groupThreeCombinations(str_split($uniqueSelection))),
                '组六'=>implode(' ',$this->groupSixCombinations(str_split($uniqueSelection))),
                default=>'000',
            };
            $row=['id'=>$lineId,'raw_text'=>$rawOriginal,'status'=>'success','reason'=>null,'number_text'=>$numberText,'category'=>$category,'amount'=>number_format($spec['amount']*$categoryCount,2,'.',''),'count'=>$categoryCount,'play_type'=>$identity['name'],'settlement_text'=>$uniqueSelection.' '.$identity['name'].' '.$category];
            if($spec['play']==='复式')$row['display_number_text']='复'.$uniqueSelection;
            $rows[]=$row;
        }
        $merged=[];
        foreach($rows as $row){
            if(!in_array($row['play_type'],['组三','组六'],true)){$merged[]=$row;continue;}
            $key=$row['play_type'].'|'.$row['category'];
            if(!isset($merged[$key])){$merged[$key]=$row;continue;}
            $merged[$key]['number_text'].=' '.$row['number_text'];
            $merged[$key]['amount']=number_format((float)$merged[$key]['amount']+(float)$row['amount'],2,'.','');
            $merged[$key]['count']+=(int)$row['count'];
            $merged[$key]['settlement_text']=$merged[$key]['number_text'].' '.$row['play_type'].' '.$row['category'];
        }
        return array_values($merged);
    }

    /** @return ?array<int,array<string,mixed>> */
    private function parseDragBets(string $rawOriginal,string $raw,string $category,int $lineId,float $unitStake): ?array
    {
        $double=preg_match($this->rules->pattern('double_drag_marker'),$raw)===1;
        $pattern=$this->rules->pattern($double?'double_drag_group':'single_drag_group');
        if(!preg_match_all($pattern,$raw,$groups,PREG_SET_ORDER))return null;
        $specs=[];
        if(!$double&&preg_match($this->rules->pattern('multi_play_shared_amount'),$raw,$shared)){
            preg_match_all($this->rules->pattern('multi_play_name'),$shared[1],$plays);
            foreach(array_unique($plays[0]??[])as$play)if(in_array($play,['组三','组六'],true))$specs[]=['play'=>$play,'amount'=>$this->rules->amountWithUnit((float)$shared[2],(string)($shared[3]??''),$unitStake)];
        }
        if($specs===[]&&preg_match_all($this->rules->pattern('multi_play_amount'),$raw,$amounts,PREG_SET_ORDER)){
            foreach($amounts as $amount)if(in_array($amount[1],$double?['组六']:['组三','组六'],true))$specs[]=['play'=>$amount[1],'amount'=>$this->rules->amountWithUnit((float)$amount[2],(string)($amount[3]??''),$unitStake)];
        }
        if($specs===[]&&preg_match_all($this->rules->pattern('drag_play_amount'),$raw,$amounts,PREG_SET_ORDER)){
            foreach($amounts as $amount)if(in_array($amount[1],$double?['组六']:['组三','组六'],true))$specs[]=['play'=>$amount[1],'amount'=>$this->rules->amountWithUnit((float)$amount[2],(string)($amount[3]??''),$unitStake)];
        }
        if($specs===[]&&$double&&preg_match($this->rules->pattern('per_unit_amount'),$raw,$amount))$specs[]=['play'=>'组六','amount'=>$this->rules->amountWithUnit((float)$amount[1],(string)($amount[2]??''),$unitStake)];
        if($specs===[])return [$this->failure($lineId,$rawOriginal,'胆拖玩法或金额不明确')];
        $rows=[];$categoryCount=$category==='福体'?2:1;
        foreach($groups as $group){$bankers=implode('',$this->uniqueDigits($group[1]));$drags=implode('',$this->uniqueDigits($group[2]));if(strlen($bankers)!==($double?2:1)||strpbrk($drags,$bankers)!==false)return [$this->failure($lineId,$rawOriginal,'胆码与拖码必须互不重复')];foreach($specs as $spec){$identity=$this->rules->dragPlay($spec['play'],strlen($bankers),strlen($drags));if($identity===null)return [$this->failure($lineId,$rawOriginal,'胆码或拖码数量与玩法不一致')];$rows[]=['id'=>$lineId,'raw_text'=>$rawOriginal,'status'=>'success','reason'=>null,'number_text'=>'000','category'=>$category,'amount'=>number_format($spec['amount']*$categoryCount,2,'.',''),'count'=>$categoryCount,'play_type'=>$identity['name'],'settlement_text'=>$identity['category'].' 胆'.$bankers.'拖'.$drags.' '.$identity['name'].' '.$category];}}
        return $rows;
    }

    /** @return ?array<string, mixed> */
    private function parseCatalogBet(string $rawOriginal, string $raw, string $category, int $lineId, float $unitStake): ?array
    {
        $selection = '';
        $numberText = '000';
        $displayNumberText = '';
        $playType = null;
        $amount = 0.0;
        if (preg_match($this->rules->pattern('catalog_digit_set_bet'), $raw, $match)) {
            $selection = implode('', $this->uniqueDigits($match[1]));
            $identity = $this->rules->catalogPlay($match[2], $match[3]);
            if ($identity === null || strlen($selection) !== $identity['count']) {
                return $this->failure($lineId, $rawOriginal, '所选数字数量与玩法不一致');
            }
            $playType = $identity['name'];
            if ($match[2] === '组三') $numberText = implode(' ', $this->groupThreeCombinations(str_split($selection)));
            if ($match[2] === '组六') $numberText = implode(' ', $this->groupSixCombinations(str_split($selection)));
            if ($match[2] === '复式') $displayNumberText = '复'.$selection;
            $amount = $this->rules->amountWithUnit((float)$match[5], (string)($match[6] ?? ''), $unitStake);
        } elseif (preg_match($this->rules->pattern('group_package_bet'), $raw, $match)) {
            $playType = $match[1];
            $amount = $this->rules->amountWithUnit((float)$match[2], (string)($match[3] ?? ''), $unitStake);
        }
        if ($playType === null) return null;
        $categoryCount = $category === '福体' ? 2 : 1;
        $settlementText = trim(($selection === '' ? '' : $selection.' ').$playType.' '.$category);
        $row = [
            'id'=>$lineId, 'raw_text'=>$rawOriginal, 'status'=>'success', 'reason'=>null,
            'number_text'=>$numberText, 'category'=>$category, 'amount'=>number_format($amount*$categoryCount,2,'.',''),
            'count'=>$categoryCount, 'play_type'=>$playType, 'settlement_text'=>$settlementText,
        ];
        if($displayNumberText!=='')$row['display_number_text']=$displayNumberText;
        return $row;
    }

    /** @param array<int, string> $digits @return array<int, string> */
    private function groupSixCombinations(array $digits): array
    {
        $numbers = [];
        $count = count($digits);
        for ($first = 0; $first < $count - 2; $first++) {
            for ($second = $first + 1; $second < $count - 1; $second++) {
                for ($third = $second + 1; $third < $count; $third++) $numbers[] = $digits[$first].$digits[$second].$digits[$third];
            }
        }
        return $numbers;
    }

    /** @param array<int, string> $digits @return array<int, string> */
    private function groupThreeCombinations(array $digits): array
    {
        $numbers = [];
        foreach ($digits as $pair) foreach ($digits as $single) if ($pair !== $single) $numbers[] = $pair.$pair.$single;
        return $numbers;
    }

    private function isStandaloneNumberLine(string $line): bool
    {
        return preg_match($this->rules->pattern('standalone_number_line'), trim($line)) === 1;
    }

    /** @param array<int, string> $numbers @return array<string, array<int, string>> */
    private function groupNumbersByPlay(array $numbers): array
    {
        $groups = ['组三' => [], '组六' => [], '豹子' => []];
        foreach ($numbers as $number) {
            $unique = count(array_unique(str_split($number)));
            $groups[$unique === 1 ? '豹子' : ($unique === 2 ? '组三' : '组六')][] = $number;
        }
        return array_filter($groups);
    }

    /** @return array{0: string, 1: float, 2: bool} */
    private function extractUnitAmount(string $raw, float $unitStake): array
    {
        if (!preg_match_all($this->rules->pattern('per_unit_amount'), $raw, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            // Whole-ticket amounts are also commonly written directly after a
            // play name, e.g. “豹子全包1500元”. Treat that as a total amount.
            if (preg_match($this->rules->pattern('whole_ticket_amount'), $raw, $total, PREG_OFFSET_CAPTURE)) {
                $offset = (int) $total[0][1];
                $length = strlen((string) $total[0][0]);
                return [trim(substr($raw, 0, $offset) . ' ' . substr($raw, $offset + $length)), $this->rules->amountWithUnit((float) $total[1][0], (string) ($total[2][0] ?? ''), $unitStake), false];
            }
            return [$raw, 0.0, false];
        }

        $match = $matches[array_key_last($matches)];
        $lead = mb_substr((string) $match[0][0], 0, 1);
        $amount = $this->rules->amountWithUnit((float) $match[1][0], (string) ($match[2][0] ?? ''), $unitStake);
        $offset = (int) $match[0][1];
        $length = strlen((string) $match[0][0]);
        $numberSource = substr($raw, 0, $offset) . ' ' . substr($raw, $offset + $length);
        return [$numberSource, $amount, $this->rules->isPerUnitLead($lead)];
    }

    /** @return ?array{numbers: array<int, string>, count: int} */
    private function parsePosition(string $raw): ?array
    {
        if (!preg_match($this->rules->pattern('position'), $raw, $match)) {
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

    /** @return ?array{numbers: array<int,string>, count:int} */
    private function parsePartialPosition(string $raw): ?array
    {
        if (!preg_match_all($this->rules->pattern('position_values'),$raw,$matches,PREG_SET_ORDER)) return null;
        $positions=[];
        foreach($matches as $match)$positions[$match[1]]=$this->uniqueDigits($match[2]);
        if($positions===[]||count($positions)>=3)return null;
        $numbers=['000'];
        foreach($positions as $marker=>$values){$index=['百'=>0,'十'=>1,'个'=>2][$marker];$expanded=[];foreach($numbers as $number)foreach($values as $value){$number[$index]=$value;$expanded[]=$number;}$numbers=$expanded;}
        return ['numbers'=>array_values(array_unique($numbers)),'count'=>count(array_unique($numbers))];
    }

    private function parseSinglePosition(string $raw): ?string
    {
        if (!preg_match($this->rules->pattern('single_position'), $raw, $match)) return null;
        $digits = ['0', '0', '0'];
        $index = ['百' => 0, '十' => 1, '个' => 2][$match[2]];
        $digits[$index] = $match[1];
        return implode('', $digits);
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
        preg_match_all($this->rules->pattern('standalone_number'), $raw, $matches);
        $numbers = [];
        foreach (($matches[1] ?? []) as $number) {
            $numbers[] = str_pad((string) $number, 3, '0', STR_PAD_LEFT);
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
        $numberCount = count($numbers);
        $categoryCount = $numberCount > 0 ? max(1, (int) round($count / $numberCount)) : 1;
        $recordCount = count(array_unique($numbers)) * $categoryCount;

        return [
            'id' => $lineId,
            'raw_text' => $rawOriginal,
            'status' => 'success',
            'reason' => null,
            'number_text' => implode(' ', $numbers),
            'category' => $category,
            'amount' => number_format($totalAmount, 2, '.', ''),
            'count' => $recordCount,
            'stake_count' => $count,
            'code_count' => $recordCount,
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

}
