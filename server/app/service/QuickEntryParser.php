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
        // Legacy ticket shorthand: number lines followed by “一元单，一元组12”.
        // Expand it into explicit direct/组六 lines so both plays and the
        // shared total are preserved; a standalone 合计 line is only a grand
        // total marker and must not become a number token.
        for ($i=0; $i<count($lines); $i++) {
            if (preg_match('/^\s*(福体|福|体)?\s*((?:\d{3}\s*){2,})([一二两三四五六七八九十\d]+)\s*(?:直|单)\s*([一二两三四五六七八九十\d]+)\s*组\s*(?:[,，]?\s*(福体|福|体))?\s*$/u', trim((string)$lines[$i]), $inlineSuffix)) {
                $prefix=trim((string)($inlineSuffix[1]??'')) ?: trim((string)($inlineSuffix[5]??'')); $numbers=preg_split('/\s+/u',trim((string)$inlineSuffix[2]))?:[]; $direct=$this->countToken((string)$inlineSuffix[3]); $group=$this->countToken((string)$inlineSuffix[4]);
                if($prefix!==''&&$numbers!==[]&&$direct>0&&$group>0){$lines=array_merge(array_slice($lines,0,$i),[$prefix.implode(' ',$numbers).'直各'.($direct*2).'元',$prefix.implode(' ',$numbers).'组各'.($group*2).'元'],array_slice($lines,$i+1));$i++;}
                continue;
            }
            if (preg_match('/^\s*([一二两三四五六七八九十\d]+)\s*单\s*([一二两三四五六七八九十\d]+)\s*组\s*$/u', trim((string)$lines[$i]), $countSuffix)) {
                $start=$i-1; while($start>=0 && preg_match('/^\s*\.?\s*(?:福体|福|体)?\s*[0-9]{3}(?:[.\s]+[0-9]{3})*\s*$/u',(string)$lines[$start]))$start--; $start++;
                $joined=implode(' ',array_slice($lines,$start,$i-$start)); preg_match_all('/\d{3}/',$joined,$nums); $numbers=array_values(array_unique($nums[0]??[]));
                if($numbers!==[]){$prefix=preg_match('/^\s*\.?\s*(福体|福|体)/u',(string)$lines[$start],$pm)?$pm[1]:'';$direct=$this->countToken((string)$countSuffix[1]);$group=$this->countToken((string)$countSuffix[2]);$lines=array_merge(array_slice($lines,0,$start),[$prefix.implode(' ',$numbers).'直各'.($direct*2).'元',$prefix.implode(' ',$numbers).'组六各'.($group*2).'元'],array_slice($lines,$i+1));$i=$start+1;}
                continue;
            }
            if (preg_match('/一元单\s*[,，]?\s*一元组\s*(\d+(?:\.\d+)?)/u', (string)$lines[$i], $suffix)) {
                $start=$i-1; while($start>=0 && preg_match('/^\s*\.?\s*(?:福体|福|体)?\s*[0-9]{3}(?:[.\s]+[0-9]{3})*\s*$/u',(string)$lines[$start]))$start--; $start++;
                $joined=implode(' ',array_slice($lines,$start,$i-$start)); preg_match_all('/\d{3}/',$joined,$nums); $numbers=array_values(array_unique($nums[0]??[]));
                if($numbers!==[]){$prefix=preg_match('/^\s*\.?\s*(福体|福|体)/u',(string)$lines[$start],$pm)?$pm[1]:'';$lines=array_merge(array_slice($lines,0,$start),[$prefix.implode(' ',$numbers).'直各1元',$prefix.implode(' ',$numbers).'组六各1元'],array_slice($lines,$i+1));$i=$start+1;}
            }
        }
        $lines=array_values(array_filter($lines,static fn(string $line):bool=>preg_match('/^\s*(?:🈴|合)\s*\d+(?:\.\d+)?\s*$/u',trim($line))!==1));
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
            // A parser branch must never leak an empty array to the API: the
            // front end expects every result row to carry status/reason.
            if ($parsed === []) {
                $parsed = $this->failure($lineId, $rawOriginal, '未识别到有效玩法');
            }
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
            $category = $lottery === '排列三' ? '体' : ($lottery === '福彩3D' ? '福' : $lottery);
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
        $lotteries=match($category){'福'=>['FC3D'],'体'=>['PL3'],'福体'=>['FC3D','PL3'],default=>[$fallbackLottery]};
        $source=$this->rules->normalize((string)($row['settlement_text']??$row['raw_text']??''));
        $playType=(string)($row['play_type']??'');
        preg_match_all('/[百十个]/u',$source,$positionMatches);
        $markers=array_values(array_unique($positionMatches[0]??[]));
        $play=match(true){
            $playType==='双飞'=>'DOUBLE_FLY',
            $playType==='胆拖'=>'DANTUO',
            // Sticky/粘边赖 remains the ordinary Z3/Z6 play in the AST;
            // mode=sticky carries the distinction from normal group bets.
            str_contains($playType,'组六赖')||str_contains($playType,'组六')&&str_ends_with($playType,'码')&&($row['mode']??'')==='sticky'=>'Z6',
            str_contains($playType,'组三赖')||str_contains($playType,'组三')&&str_ends_with($playType,'码')&&($row['mode']??'')==='sticky'=>'Z3',
            str_starts_with($playType,'组三')||(str_contains($source,'组三')&&str_contains($source,'胆拖'))=>'Z3',
            str_starts_with($playType,'组六')||(str_contains($source,'组六')&&str_contains($source,'胆拖'))=>'Z6',
            str_starts_with($playType,'和值')||str_starts_with($playType,'和')=>'HZ',
            str_starts_with($playType,'跨度')=>'KD',
            count($markers)===1=>'1D',
            count($markers)===2=>'2D',
            $playType==='直'||count($markers)>=3=>'ZX',
            default=>$playType,
        };
        $amountType=(string)($row['amount_type']??'money');
        $ast=['lottery'=>$lotteries,'play'=>$play,'amount'=>(float)($row['amount']??0),'amountType'=>$amountType,'money'=>$amountType==='money'?(float)($row['amount']??0):0.0,'bets'=>(int)($row['bet_count']??$row['stake_count']??0),'multiplier'=>(float)($row['multiplier']??0),'finalAmount'=>(float)($row['amount']??0),'mode'=>(string)($row['mode']??'normal'),'each'=>str_contains($source,'各')||str_contains($source,'每')];
        $numbers=preg_split('/\s+/',trim((string)($row['number_text']??'')))?:[];
        $numbers=array_values(array_filter($numbers,static fn(string $number):bool=>trim($number)!==''));
        $display = (string)($row['display_number_text'] ?? '');
        // 000 is the legacy placeholder for non-number plays. Treat it as
        // empty here so sticky selections and胆拖 can expose their real AST
        // fields instead of losing the selected digits.
        if ($play === 'DOUBLE_FLY' && $display !== '') {
            $ast['digits'] = $display;
            unset($ast['numbers']);
        } elseif (($numbers === [] || ($numbers === ['000'] && $play !== 'ZX')) && $display !== '') {
            if ($play === 'DANTUO') {
                if (preg_match('/胆(\d{1,2})拖(\d{1,9})/u', $display, $drag)) { $ast['dan']=$drag[1]; $ast['tuo']=$drag[2]; }
            } elseif (preg_match('/^[三六]?\d{4,10}$/u', $display)) $ast['numbers'] = [$display];
        }
        if($play !== 'DOUBLE_FLY' && $numbers!==[]&&!($numbers===['000']&&$play!=='ZX'))$ast['numbers']=$numbers;
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
        // A single pasted line may contain three independent plays, e.g.
        // “福377直20 3百7十20米 1胆200米”. Split these before generic number
        // extraction, otherwise the amounts and markers bleed together and
        // produce one invalid detail.
        if (preg_match('/^\s*(福体|福|体)?\s*(\d{3})\s*(?:直|单)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?\s+(\d)\s*百\s*(\d)\s*十\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?\s+(\d)\s*(?:独胆|胆)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?\s*$/u', $rawOriginal, $mixed)) {
            $prefix=(string)($mixed[1]??'');
            $segments=[$prefix.$mixed[2].'直'.$mixed[3].($mixed[4]??''),$prefix.$mixed[5].'百'.$mixed[6].'十'.$mixed[7].($mixed[8]??''),$prefix.$mixed[9].'胆'.$mixed[10].($mixed[11]??'')];
            $rows=[]; foreach($segments as $segment){$parsed=$this->parseLine($segment,$lottery,$lineId,$unitStake);if(isset($parsed[0])&&is_array($parsed[0]))$rows=array_merge($rows,$parsed);else$rows[]=$parsed;} return $rows;
        }
        $stickyAlias = $this->parseStickyAliasBet($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($stickyAlias !== null) return $stickyAlias;
        // “值”明确表示现金金额，先转成带元单位的内部表达，避免被
        // 号码解析成普通数字或与“和值”中的“值”混淆。
        if (preg_match($this->rules->pattern('value_amount'), $raw, $valueMatch)) {
            $raw = preg_replace($this->rules->pattern('value_amount'), (string)$valueMatch[1].'元', $raw, 1) ?? $raw;
            $working = $raw;
        }
        $outcomeBet = $this->parseOutcomeBet($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($outcomeBet !== null) return $outcomeBet;
        $outcomeSet = $this->parseOutcomeSet($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($outcomeSet !== null) return $outcomeSet;
        $sticky = $this->parseStickyGroup($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($sticky !== null) return $sticky;
        $standaloneDantuo = $this->parseStandaloneDantuo($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($standaloneDantuo !== null) return $standaloneDantuo;
        $doubleFly = $this->parseDoubleFly($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($doubleFly !== null) return $doubleFly;
        $listedFly = $this->parseListedFly($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($listedFly !== null) return $listedFly;
        $countedDirectGroup = $this->parseCountedDirectGroup($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($countedDirectGroup !== null) return $countedDirectGroup;
        $listedCountedDirectGroup = $this->parseListedCountedDirectGroup($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($listedCountedDirectGroup !== null) return $listedCountedDirectGroup;
        $directGroupList = $this->parseDirectGroupList($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($directGroupList !== null) return $directGroupList;
        $standaloneDan = $this->parseStandaloneDan($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($standaloneDan !== null) return $standaloneDan;
        // 组合目录玩法必须拆成独立明细，例如“123复式组六”应同时生成
        // 复式和组六两条玩法；赖玩法使用自己的固定一倍本金，不能按普通注额解析。
        $compositeCatalog = $this->parseCompositeCatalogBet($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($compositeCatalog !== null) return $compositeCatalog;
        $lianCatalog = $this->parseLianCatalogBet($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($lianCatalog !== null) return $lianCatalog;
        $plainGroup = $this->parsePlainGroupBet($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($plainGroup !== null) return $plainGroup;
        $compactDirectGroup = $this->parseCompactDirectGroupBet($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($compactDirectGroup !== null) return $compactDirectGroup;
        // Compact direct syntax has no delimiter between the three-digit
        // number and its stake (12310, 12310元, 1235倍). Handle it before
        // standaloneNumbers(), which intentionally does not split a longer
        // digit run into 123 + 10.
        $compactDirect = $this->parseCompactDirectBet($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($compactDirect !== null) return $compactDirect;
        $catalogBet = $this->parseCatalogBet($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($catalogBet !== null) return $catalogBet;
        $dragBets = $this->parseDragBets($rawOriginal,$raw,$category,$lineId,$unitStake);
        if ($dragBets !== null) return $dragBets;
        $orderedGroupBets = $this->parseOrderedGroupPair($rawOriginal, $raw, $category, $lineId, $unitStake);
        if ($orderedGroupBets !== null) return $orderedGroupBets;
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
            $row = $this->successOrAmountFailure(
                $lineId,
                $rawOriginal,
                $numbers,
                $category,
                $count,
                $unitAmount,
                $perUnit,
                $declaredTotal
            );
            $row['number_text']=$this->positionExpression($numberSource);
            $row['play_type']='定位';
            return $row;
        }

        $partialPosition = $this->parsePartialPosition($numberSource);
        if ($partialPosition !== null) {
            $row=$this->successOrAmountFailure($lineId,$rawOriginal,$partialPosition['numbers'],$category,$partialPosition['count']*$categoryCount,$unitAmount,$perUnit,$declaredTotal);
            $row['number_text']=$this->positionExpression($numberSource);
            $row['play_type']='定位';
            return $row;
        }

        $singlePosition = $this->parseSinglePosition($numberSource);
        if ($singlePosition !== null) {
            $row=$this->successOrAmountFailure(
                $lineId,
                $rawOriginal,
                [$singlePosition],
                $category,
                1,
                $unitAmount,
                $perUnit,
                $declaredTotal
            );
            $row['number_text']=$this->positionExpression($numberSource);
            $row['play_type']='定位';
            return $row;
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
            // Compact direct syntax: 12310 means number 123, ten stakes;
            // 123 456 789 5 means each listed number carries five stakes.
            if (preg_match('/^(\d{3})(\d{1,3})$/u', $implicitSource, $compact)) {
                $number = str_pad($compact[1], 3, '0', STR_PAD_LEFT);
                $bets = (int)$compact[2];
                $row = $this->successOrAmountFailure($lineId, $rawOriginal, [$number], $category, $categoryCount, $bets * $unitStake, true, $declaredTotal);
                $row['number_text']=$number.'直'; $row['play_type']='直'; $row['amount_type']='bet'; $row['bet_count']=$bets;
                $row['settlement_text']=$number.' 直各'.number_format($bets*$unitStake,2,'.','').'元 '.$category;
                return $row;
            }
            $tokens = preg_split('/\s+/u', $implicitSource, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($tokens) >= 2 && preg_match('/^\d{1,3}$/', (string)end($tokens)) === 1) {
                $bets = (int)array_pop($tokens);
                if ($bets > 0 && $tokens !== [] && count(array_filter($tokens, static fn(string $token): bool => preg_match('/^\d{3}$/', $token) === 1)) === count($tokens)) {
                    $row = $this->successOrAmountFailure($lineId, $rawOriginal, $tokens, $category, count($tokens)*$categoryCount, $bets*$unitStake, true, $declaredTotal);
                    $row['number_text']=implode('直 ', $tokens).'直'; $row['play_type']='直'; $row['amount_type']='bet'; $row['bet_count']=$bets;
                    $row['settlement_text']=implode(' ', $tokens).' 直各'.number_format($bets*$unitStake,2,'.','').'元 '.$category;
                    return $row;
                }
            }
            if (preg_match('/^(\d{3})(\d{1,3})倍$/u', $implicitSource, $compactMultiplier)) {
                $number = $compactMultiplier[1]; $multiplier = (float)$compactMultiplier[2];
                $row = $this->successOrAmountFailure($lineId, $rawOriginal, [$number], $category, $categoryCount, $multiplier*$unitStake, true, $declaredTotal);
                $row['number_text']=$number.'直'; $row['play_type']='直'; $row['amount_type']='multiplier'; $row['multiplier']=$multiplier;
                $row['settlement_text']=$number.' 直各'.number_format($multiplier*$unitStake,2,'.','').'元 '.$category;
                return $row;
            }
            if(count($numbers)!==1||preg_match('/^\d{3}(?:\s+\d+(?:\.\d+)?\s*倍)?$/',$implicitSource)!==1)return $this->failure($lineId,$rawOriginal,'未识别到玩法', $numbers);
            $directAmount=$unitAmount>0?$unitAmount:$unitStake;
            $row=$this->successOrAmountFailure($lineId,$rawOriginal,$numbers,$category,$categoryCount,$directAmount,true,$declaredTotal);
            $row['number_text']=$numbers[0].'直'; $row['play_type']='直';
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
        $compactCombinedTotal = false;
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
        // Compact mixed syntax may put the whole-ticket total directly after
        // the marker, e.g. “五单一组144元”. It is not a 3-digit number and
        // must not be fed into standaloneNumbers(). A trailing 各/每 amount
        // remains a per-number amount and is intentionally left untouched.
        if ($combinedMatched > 0 && $declaredTotal === null
            && preg_match('/组\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛)\s*$/u', $working, $totalMatch, PREG_OFFSET_CAPTURE) === 1) {
            $declaredTotal = $this->rules->amountWithUnit((float)$totalMatch[1][0], (string)($totalMatch[2][0] ?? ''), $unitStake);
            $numberSource = preg_replace('/\s*\d+(?:\.\d+)?\s*(?:元|米|块|角|毛)\s*$/u', '', $numberSource) ?? $numberSource;
            $compactCombinedTotal = true;
        }
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
                $expandedPlayNumbers = $playNumbers;
                if ($compactCombinedTotal && in_array($playType, ['组三', '组六'], true)) {
                    // A group entry is displayed once per unordered digit set,
                    // while its amount still covers all permutations listed in
                    // the compact ticket (e.g. six permutations per 组六 set).
                    $uniqueGroups = [];
                    foreach ($playNumbers as $playNumber) {
                        $digits = str_split($playNumber);
                        sort($digits);
                        $key = implode('', $digits);
                        if (!isset($uniqueGroups[$key])) $uniqueGroups[$key] = $playNumber;
                    }
                    $playNumbers = array_values($uniqueGroups);
                }
                $row = $this->successOrAmountFailure($lineId, $rawOriginal, $playNumbers, $category, count($playNumbers) * $categoryCount, (float)$spec['unit_amount'], true, null);
                if ($compactCombinedTotal && in_array($playType, ['组三', '组六'], true)) {
                    $row['amount'] = number_format((float)$spec['unit_amount'] * count($expandedPlayNumbers) * $categoryCount, 2, '.', '');
                }
                $row['play_type'] = $playType;
                $row['number_text'] = implode(' ', array_map(static fn(string $value): string => in_array($playType, ['组三','组六'], true) ? $value.'组' : $value.'直', $playNumbers));
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
                if(($play==='组三'&&$uniqueCount===2)||($play==='组六'&&$uniqueCount===3)){$playType=$play;$numberText=$rawSelection.'组';}
                elseif($play==='组三'&&$uniqueCount===3){$identity=$this->rules->inferredCatalogPlay($play,3);$playType=$identity['name'];$numberText='三'.$rawSelection;}
                else return [$this->failure($lineId,$rawOriginal,'号码形态与玩法不一致')];
            } else {
                $identity = $this->rules->inferredCatalogPlay($play, count($digits));
                if ($identity === null) return [$this->failure($lineId,$rawOriginal,'所选数字数量与玩法不一致')];
                $playType=$identity['name'];
                $numberText=match($play){
                    '组三'=>'三'.$rawSelection,
                    '组六'=>'六'.$rawSelection,
                    '复式'=>'复'.$rawSelection,
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

    /**
     * Parse a compact three-digit direct bet such as 12310, 12310元 or
     * 1235倍.  The suffix is deliberately required so a plain 123 remains
     covered by the normal implicit-direct path.
     * @return ?array<string,mixed>
     */
    private function parseCompactDirectBet(string $rawOriginal, string $raw, string $category, int $lineId, float $unitStake): ?array
    {
        $source = trim(str_replace(['福体', '福', '体'], '', $raw));
        if (!preg_match('/^(\d{3})(\d{1,3})(元|米|块|倍)?$/u', $source, $match)) {
            return null;
        }
        $number = str_pad($match[1], 3, '0', STR_PAD_LEFT);
        $value = (float)$match[2];
        $unit = (string)($match[3] ?? '');
        $categoryCount = $category === '福体' ? 2 : 1;
        if ($unit === '倍') {
            $amountType = 'multiplier';
            $amount = $value * $unitStake;
            $multiplier = $value;
            $betCount = 1;
        } elseif ($unit !== '') {
            $amountType = 'money';
            $amount = $value;
            $multiplier = 1.0;
            $betCount = 0;
        } else {
            $amountType = 'bet';
            $amount = $value * $unitStake;
            $multiplier = 1.0;
            $betCount = (int)$value;
        }
        $amount *= $categoryCount;
        return [
            'id'=>$lineId, 'raw_text'=>$rawOriginal, 'status'=>'success', 'reason'=>null,
            'number_text'=>$number.'直', 'category'=>$category,
            'amount'=>number_format($amount, 2, '.', ''), 'count'=>$categoryCount,
            'stake_count'=>$categoryCount, 'code_count'=>$categoryCount,
            'play_type'=>'直', 'amount_type'=>$amountType,
            'bet_count'=>$betCount, 'multiplier'=>$multiplier,
            'settlement_text'=>$number.' 直'.($unit === '倍' ? $value.'倍' : '各'.number_format($amount / $categoryCount, 2, '.', '').'元').' '.$category,
        ];
    }

    /**
     * Parse a number set longer than three digits as a sticky/赖 group.
     * The set is one bet and is deliberately not expanded into combinations.
     * @return ?array<string, mixed>
     */
    private function parseStickyGroup(string $rawOriginal, string $raw, string $category, int $lineId, float $unitStake): ?array
    {
        $match = null;
        $family = null;
        $mode = 'sticky';
        // Plain multi-code 组三/组六 must flow through parseMultiCodeBets so
        // the selected digit count maps to 组三五码/组六六码/etc.  Keep only
        // the explicit 粘边赖 forms in this sticky parser.
        foreach ([['sticky_lian6_bet', '组六', 'Z6_LIAN'], ['sticky_lian3_bet', '组三', 'Z3_LIAN']] as [$pattern, $play, $astPlay]) {
            if (preg_match($this->rules->pattern($pattern), $raw, $candidate)) {
                $match = $candidate;
                $family = [$play, $astPlay, str_contains($pattern, 'lian')];
                break;
            }
        }
        if ($match === null || $family === null) return null;
        $selection = (string)$match[1];
        $isLian = (bool)$family[2];
        $valueIndex = $isLian ? 3 : 2;
        $unitIndex = $isLian ? 4 : 3;
        $amountValue = isset($match[$valueIndex]) && $match[$valueIndex] !== '' ? (float)$match[$valueIndex] : 1.0;
        $unit = (string)($match[$unitIndex] ?? '');
        if ($isLian) {
            $playKey = $family[0] === '组六' ? 'Z6_LIAN' : 'Z3_LIAN';
            // 粘边赖的1倍本金随选号个数变化（例如组三三码96元、
            // 组六三码170元），不是普通组选的10元单位。
            $base = $this->rules->baseStake($family[0] === '组六' ? 'Z6' : 'Z3', count($this->uniqueDigits($selection)));
        } else {
            $playKey = $family[0] === '组六' ? 'Z6' : 'Z3';
            $base = 10.0;
        }
        if ($isLian) {
            // 图片规则：不足1倍按1倍，非整数倍按已达到的完整倍数计。
            $rawAmount = $unit === '倍' ? $amountValue : $this->rules->amountWithUnit($amountValue, $unit, $unitStake);
            $effectiveMultiplier = $unit === '倍' ? max(1.0, $amountValue) : max(1.0, floor($rawAmount / $base + 1e-9));
            $finalAmount = $base * $effectiveMultiplier;
            $amountType = $unit === '倍' ? 'multiplier' : 'money';
            $multiplier = $effectiveMultiplier;
        } elseif ($unit === '倍') {
            $finalAmount = $base * $amountValue;
            $amountType = 'multiplier';
            $multiplier = $amountValue;
        } elseif ($unit === '元' || in_array($unit, ['米', '块'], true)) {
            $finalAmount = $amountValue;
            $amountType = 'money';
            $multiplier = 1.0;
        } else {
            $finalAmount = $amountValue * $unitStake;
            $amountType = 'bet';
            $multiplier = 1.0;
        }
        $categoryCount = $category === '福体' ? 2 : 1;
        $finalAmount *= $categoryCount;
        $canonicalPlay = $isLian ? $family[0].'赖'.$match[2].'码' : $family[0];
        $canonical = $canonicalPlay.' '.$category;
        $row = [
            'id'=>$lineId, 'raw_text'=>$rawOriginal, 'status'=>'success', 'reason'=>null,
            'number_text'=>($isLian ? ($family[0]==='组六'?'六赖':'三赖') : ($family[0]==='组六'?'六':'三')).$selection, 'display_number_text'=>$selection, 'category'=>$category,
            'amount'=>number_format($finalAmount, 2, '.', ''), 'count'=>$categoryCount,
            'play_type'=>$canonicalPlay,
            'settlement_text'=>$selection.' '.$canonical,
            'amount_type'=>$amountType, 'multiplier'=>$multiplier, 'mode'=>$mode,
            'play_key'=>$playKey, 'bet_count'=>(int)$amountValue,
        ];
        return $row;
    }

    /** Parse the compact, generic 胆拖 form when no group family is supplied. */
    private function parseStandaloneDantuo(string $rawOriginal, string $raw, string $category, int $lineId, float $unitStake): ?array
    {
        if (preg_match('/^\s*(?:福体|福|体)?\s*(组三|组六)\s*(?:胆\s*)?(\d{1,2})\s*(?:胆拖|拖)\s*(\d{2,9})\s*(?:各|每)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?\s*$/u', $raw, $named)) {
            $family=$named[1]; $dan=$named[2]; $tuo=$named[3]; $value=(float)$named[4]; $unit=(string)($named[5]??''); $amount=$this->rules->amountWithUnit($value,$unit,$unitStake); $categoryCount=$category==='福体'?2:1; $dragPlay=$this->rules->dragPlay($family,(int)strlen($dan),(int)strlen($tuo)); if($dragPlay===null)return [$this->failure($lineId,$rawOriginal,'胆码或拖码数量与玩法不一致')];
            return [[ 'id'=>$lineId,'raw_text'=>$rawOriginal,'status'=>'success','reason'=>null,'number_text'=>'胆'.$dan.'拖'.$tuo,'display_number_text'=>'胆'.$dan.'拖'.$tuo,'category'=>$category,'amount'=>number_format($amount*$categoryCount,2,'.',''),'count'=>$categoryCount,'play_type'=>$dragPlay['name'],'settlement_text'=>$dragPlay['category'].' 胆'.$dan.'拖'.$tuo.' '.$dragPlay['name'].' '.$category ]];
        }
        if (preg_match('/^\s*(?:福体|福|体)?\s*(?:胆\s*)?(\d{1,2})\s*胆?拖\s*(\d{2,9})\s*(?:各|每)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?\s*$/u', $raw, $alias)) {
            return [$this->failure($lineId, $rawOriginal, '胆拖下注必须注明组三或组六玩法，例如“组六1胆拖23各1000米”')];
        }
        if (!preg_match($this->rules->pattern('dantuo_bet'), $raw, $match)) return null;
        $dan = (string)$match[1];
        $tuo = (string)$match[2];
        $value = isset($match[3]) && $match[3] !== '' ? (float)$match[3] : 1.0;
        $unit = (string)($match[4] ?? '');
        $type = $unit === '倍' ? 'multiplier' : ($unit === '' || $unit === '注' ? 'bet' : 'money');
        $amount = $unit === '倍' ? $value * 10.0 : ($type === 'bet' ? $value : $value);
        $categoryCount = $category === '福体' ? 2 : 1;
        return [[
            'id'=>$lineId, 'raw_text'=>$rawOriginal, 'status'=>'success', 'reason'=>null,
            'number_text'=>'胆'.$dan.'拖'.$tuo, 'display_number_text'=>'胆'.$dan.'拖'.$tuo, 'category'=>$category,
            'amount'=>number_format($amount*$categoryCount, 2, '.', ''), 'count'=>$categoryCount,
            'play_type'=>'胆拖', 'settlement_text'=>'组六胆拖 胆'.$dan.'拖'.$tuo.' '.$category,
            'amount_type'=>$type, 'multiplier'=>$type === 'multiplier' ? $value : 1.0,
            'play_key'=>'DANTUO', 'bet_count'=>$type === 'bet' ? (int)$value : 1,
        ]];
    }

    private function parseDoubleFly(string $rawOriginal, string $raw, string $category, int $lineId, float $unitStake): ?array
    {
        if (!preg_match($this->rules->pattern('double_fly_bet'), $raw, $match)) return null;
        $digits = (string)$match[1];
        $value = (float)$match[2];
        $unit = (string)($match[3] ?? '');
        // Keep the user's original “飞/双飞” label for display.  Odds
        // identity separately reclassifies repeated digits as 对子.
        $playName = '双飞';
        $type = $unit === '倍' ? 'multiplier' : ($unit === '' || $unit === '注' ? 'bet' : 'money');
        $amount = $unit === '倍' ? $value * 10.0 : ($type === 'bet' ? $value * $unitStake : $value);
        $categoryCount = $category === '福体' ? 2 : 1;
        return [[
            'id'=>$lineId, 'raw_text'=>$rawOriginal, 'status'=>'success', 'reason'=>null,
            'number_text'=>$digits.'飞', 'display_number_text'=>$digits, 'category'=>$category,
            'amount'=>number_format($amount*$categoryCount, 2, '.', ''), 'count'=>$categoryCount,
            // Keep the number before the play name so odds/matcher regexes can
            // unambiguously extract the selected pair (e.g. “77 对子”).
            'play_type'=>$playName, 'settlement_text'=>$digits.' '.$playName.' '.$category,
            'amount_type'=>$type, 'multiplier'=>$type === 'multiplier' ? $value : 1.0,
            'play_key'=>'DOUBLE_FLY', 'bet_count'=>$type === 'bet' ? (int)$value : 1,
        ]];
    }

    /** Parse a list such as “37 77双飞各6000”, splitting pairs by odds type. */
    private function parseListedFly(string $rawOriginal, string $raw, string $category, int $lineId, float $unitStake): ?array
    {
        $pattern='/^\s*(?:福体|福|体)?\s*((?:\d{2}\s+){1,}\d{2})\s*(?:双飞|飞)\s*(?:各|每)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?\s*$/u';
        if (!preg_match($pattern,$rawOriginal,$match) && !preg_match($pattern,$raw,$match)) return null;
        preg_match_all('/\d{2}/',(string)$match[1],$numberMatches);
        $numbers=array_values(array_unique($numberMatches[0]??[])); if($numbers===[])return null;
        $unitAmount=$this->rules->amountWithUnit((float)$match[2],(string)($match[3]??''),$unitStake);
        $categoryCount=$category==='福体'?2:1; $groups=['双飞'=>[],'对子'=>[]];
        foreach($numbers as $number)$groups[$number[0]===$number[1]?'对子':'双飞'][]=$number;
        $rows=[];
        foreach($groups as $play=>$selected)if($selected!==[])$rows[]=['id'=>$lineId,'raw_text'=>$rawOriginal,'status'=>'success','reason'=>null,'number_text'=>implode(' ',array_map(static fn(string $n):string=>$n.($play==='对子'?'对':'飞'),$selected)),'display_number_text'=>implode(' ',$selected),'category'=>$category,'amount'=>number_format($unitAmount*count($selected)*$categoryCount,2,'.',''),'count'=>count($selected)*$categoryCount,'stake_count'=>count($selected)*$categoryCount,'code_count'=>count($selected)*$categoryCount,'play_type'=>$play,'settlement_text'=>implode(' ', $selected).' '.$play.'各'.number_format($unitAmount,2,'.','').'元 '.$category,'amount_type'=>'money','bet_count'=>count($selected)];
        return $rows;
    }

    /** Parse “895一单五组12”: one direct stake, five group stakes, total 12. */
    private function parseCountedDirectGroup(string $rawOriginal, string $raw, string $category, int $lineId, float $unitStake): ?array
    {
        // Prefer the original text so Chinese count markers (“一单五组”)
        // remain separated from a short numeric selection such as “89”.
        $match = null;
        if (preg_match('/^\s*(?:福体|福|体)?\s*([0-9]{1,10})\s*([一二两三四五六七八九十\d]+)\s*(?:单|直)\s*([一二两三四五六七八九十\d]+)\s*(?:组|组六)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛)?\s*$/u', $rawOriginal, $originalMatch)) {
            $match = $originalMatch;
            $match[2] = $this->rules->normalize((string)$match[2]);
            $match[3] = $this->rules->normalize((string)$match[3]);
        } elseif (preg_match('/^\s*(?:福体|福|体)?\s*([0-9]{1,10})\s*(\d+)\s*(?:单|直)\s*(\d+)\s*(?:组|组六)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛)?\s*$/u', $raw, $normalizedMatch)) {
            $match = $normalizedMatch;
        }
        if ($match === null) return null;
        $selection = (string)$match[1];
        $directStakes = (int)$match[2];
        $groupStakes = (int)$match[3];
        $declaredTotal = $this->rules->amountWithUnit((float)$match[4], (string)($match[5] ?? ''), $unitStake);
        if ($directStakes < 1 || $groupStakes < 1) return null;
        $perStake = strlen($selection) === 3 ? $unitStake : 10.0;
        $categoryCount = $category === '福体' ? 2 : 1;
        $directAmount = $directStakes * $perStake * $categoryCount;
        $groupAmount = $groupStakes * $perStake * $categoryCount;
        if (abs($directAmount + $groupAmount - $declaredTotal * $categoryCount) > 0.001) {
            return [$this->failure($lineId, $rawOriginal, '句末总金额与单双组倍数不一致')];
        }
        $rows = [];
        foreach ([['直', $directStakes, $directAmount], ['组六', $groupStakes, $groupAmount]] as [$play, $stakes, $amount]) {
            $rows[] = [
                'id'=>$lineId, 'raw_text'=>$rawOriginal, 'status'=>'success', 'reason'=>null,
                'number_text'=>$selection.$play, 'display_number_text'=>$selection, 'category'=>$category,
                'amount'=>number_format($amount, 2, '.', ''), 'count'=>$categoryCount,
                'stake_count'=>$stakes * $categoryCount, 'code_count'=>$categoryCount,
                'play_type'=>$play, 'settlement_text'=>$selection.' '.$play.'各'.number_format($stakes*$perStake, 2, '.', '').'元 '.$category,
                'amount_type'=>'money', 'bet_count'=>$stakes,
            ];
        }
        return $rows;
    }

    /** Parse “418 403 901 406各四单一组计40”. */
    private function parseListedCountedDirectGroup(string $rawOriginal, string $raw, string $category, int $lineId, float $unitStake): ?array
    {
        // Chinese hundreds (e.g. “四百单”) are not safely distinguishable
        // from a pasted number suffix after normalization. Reject them with a
        // visible validation error instead of falling through and creating a
        // bogus 00x ticket; users can enter the equivalent “400单1组”.
        if (preg_match('/各\s*[一二两三四五六七八九十百]+百\s*单/u', $rawOriginal) === 1) {
            return [$this->failure($lineId, $rawOriginal, '单双组倍数格式不支持中文百位数，请使用数字格式（如400单1组）')];
        }
        $pattern = '/^\s*(?:福体|福|体)?\s*((?:\d{3}\s*){2,})各\s*([一二两三四五六七八九十\d]+)\s*单\s*([一二两三四五六七八九十\d]+)\s*组(?:\s*(?:计|合计|共)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛)?)?\s*$/u';
        if (!preg_match($pattern, $rawOriginal, $match) && !preg_match($pattern, $raw, $match)) return null;
        preg_match_all('/\d{3}/', (string)$match[1], $numberMatches);
        $numbers = array_values(array_unique($numberMatches[0] ?? []));
        if (count($numbers) < 2) return null;
        $directStakes = $this->countToken((string)$match[2]);
        $groupStakes = $this->countToken((string)$match[3]);
        if ($directStakes < 1 || $groupStakes < 1) return null;
        $declared = isset($match[4]) && $match[4] !== ''
            ? $this->rules->amountWithUnit((float)$match[4], (string)($match[5] ?? ''), $unitStake) : null;
        $categoryCount = $category === '福体' ? 2 : 1;
        $perStake = $unitStake;
        $directAmount = count($numbers) * $directStakes * $perStake * $categoryCount;
        $groupAmount = count($numbers) * $groupStakes * $perStake * $categoryCount;
        if ($declared !== null && abs($directAmount + $groupAmount - $declared * $categoryCount) > 0.001) return [$this->failure($lineId, $rawOriginal, '句末总金额与单双组倍数不一致')];
        $rows = [];
        foreach ([['直', $directStakes, $directAmount], ['组六', $groupStakes, $groupAmount]] as [$play, $stakes, $amount]) {
            $rows[] = [
                'id'=>$lineId, 'raw_text'=>$rawOriginal, 'status'=>'success', 'reason'=>null,
                'number_text'=>implode(' ', array_map(static fn(string $number): string => $number.$play, $numbers)),
                'display_number_text'=>implode(' ', $numbers), 'category'=>$category,
                'amount'=>number_format($amount, 2, '.', ''), 'count'=>count($numbers) * $categoryCount,
                'stake_count'=>count($numbers) * $stakes * $categoryCount, 'code_count'=>count($numbers) * $categoryCount,
                'play_type'=>$play, 'settlement_text'=>implode(' ', $numbers).' '.$play.'各'.number_format($stakes*$perStake, 2, '.', '').'元 '.$category,
                'amount_type'=>'money', 'bet_count'=>$stakes,
            ];
        }
        return $rows;
    }

    private function countToken(string $token): int
    {
        if (ctype_digit($token)) return (int)$token;
        $digits=['一'=>1,'二'=>2,'两'=>2,'三'=>3,'四'=>4,'五'=>5,'六'=>6,'七'=>7,'八'=>8,'九'=>9];
        if ($token==='十') return 10;
        if (preg_match('/^([一二两三四五六七八九])十([一二三四五六七八九])?$/u',$token,$m)) return ($digits[$m[1]]*10)+(($m[2]??'')!==''?$digits[$m[2]]:0);
        return $digits[$token]??0;
    }

    /** Parse one or more standalone 胆 digits, e.g. “体16胆各500”. */
    private function parseStandaloneDan(string $rawOriginal, string $raw, string $category, int $lineId, float $unitStake): ?array
    {
        if (!preg_match('/^\s*(?:福体|福|体)?\s*([0-9]{1,10})\s*(?:独胆|胆)\s*(?:各|每)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?\s*$/u', $raw, $match)) return null;
        $digits = $this->uniqueDigits((string)$match[1]);
        if ($digits === []) return null;
        $unitAmount = $this->rules->amountWithUnit((float)$match[2], (string)($match[3] ?? ''), $unitStake);
        $categoryCount = $category === '福体' ? 2 : 1;
        $tokens = $digits;
        return [[
            'id'=>$lineId, 'raw_text'=>$rawOriginal, 'status'=>'success', 'reason'=>null,
            'number_text'=>implode(' ', $tokens), 'display_number_text'=>implode(' ', $tokens), 'category'=>$category,
            'amount'=>number_format($unitAmount * count($tokens) * $categoryCount, 2, '.', ''),
            'count'=>count($tokens) * $categoryCount, 'stake_count'=>count($tokens) * $categoryCount,
            'code_count'=>count($tokens) * $categoryCount, 'play_type'=>'独胆',
            'settlement_text'=>implode('独胆 ', $tokens).'独胆各'.number_format($unitAmount, 2, '.', '').'元 '.$category,
        ]];
    }

    /** @return ?array<string, mixed> */
    private function parseOutcomeBet(string $rawOriginal, string $raw, string $category, int $lineId, float $unitStake): ?array
    {
        // Compact outcome syntax puts the selected value before the stake:
        // 和值15 8, 和值15 2倍, 跨度6 3倍.  The old suffix parser only
        // handled the inverse form (和值15各8), so recognize this form first.
        $compact = null;
        $compactPlay = null;
        if (preg_match('/^\s*(?:福体|福|体)?\s*和值\s*(2[0-7]|1\d|[0-9])\s+(\d+(?:\.\d+)?)\s*(倍|注|元|米|块)?\s*$/u', $raw, $match)) {
            $compact = $match;
            $compactPlay = '和值'.$match[1];
        } elseif (preg_match('/^\s*(?:福体|福|体)?\s*跨度\s*([0-9])\s+(\d+(?:\.\d+)?)\s*(倍|注|元|米|块)?\s*$/u', $raw, $match)) {
            $compact = $match;
            $compactPlay = '跨度'.$match[1];
        }
        if ($compact !== null && $compactPlay !== null) {
            $value = (float)$compact[2];
            $unit = (string)($compact[3] ?? '');
            $amountType = $unit === '倍' ? 'multiplier' : ($unit === '元' || $unit === '米' || $unit === '块' ? 'money' : 'bet');
            // 和值/跨度 follow the site's ten-yuan one-multiplier rule.
            $amount = $unit === '倍' ? $value * 10.0 : ($amountType === 'money' ? $value : $value * $unitStake);
            $categoryCount = $category === '福体' ? 2 : 1;
            return [[
                'id'=>$lineId, 'raw_text'=>$rawOriginal, 'status'=>'success', 'reason'=>null,
                'number_text'=>$compactPlay, 'category'=>$category,
                'amount'=>number_format($amount * $categoryCount, 2, '.', ''), 'count'=>$categoryCount,
                'play_type'=>$compactPlay, 'settlement_text'=>$compactPlay.' '.$category,
                'amount_type'=>$amountType, 'bet_count'=>$amountType === 'bet' ? (int)$value : 1,
                'multiplier'=>$amountType === 'multiplier' ? $value : 1.0,
            ]];
        }
        // Outcome/package plays are valid without a numeric selection, e.g.
        // “和大各1元” or “组三全包各1元”.  Handle this before generic number
        // extraction, otherwise the play name is incorrectly reported as a
        // missing number.
        if (preg_match('/^\s*(?:福体|福|体)?\s*(和大|和小|和单|和双|大|小|单|双|大小|大小单双|豹子全包|对子全包|组三全包|组六全包)\s*(?:各|每)?\s*(\d+(?:\.\d+)?)\s*(倍|注|元|米|块|角|毛)?\s*$/u', $raw, $outcome)) {
            $unit=(string)($outcome[3]??'');
            $amount=$this->rules->amountWithUnit((float)$outcome[2],$unit,$unitStake);
            $label=(string)$outcome[1];
            $types=$label==='大小'?['和大','和小']:($label==='大小单双'?['和大','和小','和单','和双']:[in_array($label,['大','小','单','双'],true)?'和'.$label:$label]);
            $categoryCount=$category==='福体'?2:1;
            return array_map(fn(string $type):array=>[
                'id'=>$lineId,'raw_text'=>$rawOriginal,'status'=>'success','reason'=>null,
                'number_text'=>$type,'display_number_text'=>$type,'category'=>$category,
                'amount'=>number_format($amount*$categoryCount,2,'.',''),'count'=>$categoryCount,
                'play_type'=>$type,'settlement_text'=>$type.' '.$category,
            ],$types);
        }
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
        } elseif (preg_match($this->rules->pattern('span_prefix_bet'), $raw, $match)) {
            $playType = '跨度'.$match[1];
            $unit = (string)($match[3] ?? '');
            $amount = $unit === '注'
                ? $this->rules->playAmount((float)$match[2], '', $unitStake)
                : $this->rules->amountWithUnit((float)$match[2], $unit, $unitStake);
            $playTypes = [$playType];
        } elseif (preg_match($this->rules->pattern('span_suffix_bet'), $raw, $match)) {
            $playType = '跨度'.$match[1];
            $unit = (string)($match[3] ?? '');
            $amount = $unit === '倍'
                ? (float)$match[2] * 10.0
                : ($unit === '注' || $unit === ''
                    ? $this->rules->playAmount((float)$match[2], '', $unitStake)
                    : $this->rules->amountWithUnit((float)$match[2], $unit, $unitStake));
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
            'number_text' => $type,
            'category' => $category,
            'amount' => number_format($amount * $categoryCount, 2, '.', ''),
            'count' => $categoryCount,
            'play_type' => $type,
            'settlement_text' => $type.' '.$category,
            'display_number_text' => $type,
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
        foreach(array_values(array_unique($tokens)) as $playType)$rows[]=['id'=>$lineId,'raw_text'=>$rawOriginal,'status'=>'success','reason'=>null,'number_text'=>$playType,'display_number_text'=>$playType,'category'=>$category,'amount'=>number_format($amount*$categoryCount,2,'.',''),'count'=>$categoryCount,'play_type'=>$playType,'settlement_text'=>$playType.' '.$category];
        return $rows;
    }

    /** @return ?array<int, array<string, mixed>> */
    /** Parse mixed order forms: “组三12345组六各50米” and “组六012345组三各1500米”. */
    private function parseOrderedGroupPair(string $rawOriginal,string $raw,string $category,int $lineId,float $unitStake): ?array
    {
        $pattern='/^\s*(?:福体|福|体)?\s*(组三|组六)\s*([0-9]{2,10})\s*(组三|组六)\s*(?:各|每)?\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?\s*$/u';
        if(!preg_match($pattern,$rawOriginal,$m)&&!preg_match($pattern,$raw,$m)) {
            $prefixPattern='/^\s*(?:福体|福|体)?\s*(组六|组三)\s*(组三|组六)\s*([0-9]{2,10})\s*(?:各|每)?\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?\s*$/u';
            if(!preg_match($prefixPattern,$rawOriginal,$p)&&!preg_match($prefixPattern,$raw,$p))return null;
            $m=[0,$p[1],$p[3],$p[2],$p[4],$p[5]];
        }
        if($m[1]===$m[3])return null;
        $selection=implode('',$this->uniqueDigits((string)$m[2]));$count=strlen($selection);$categoryCount=$category==='福体'?2:1;$amount=$this->rules->amountWithUnit((float)$m[4],(string)($m[5]??''),$unitStake);$rows=[];
        foreach([$m[1],$m[3]] as $family){$identity=$family==='组六'&&$count===3?['name'=>'组六']: $this->rules->inferredCatalogPlay($family,$count);if($identity===null)return [$this->failure($lineId,$rawOriginal,'所选数字数量与玩法不一致')];$name=$identity['name'];$rows[]=['id'=>$lineId,'raw_text'=>$rawOriginal,'status'=>'success','reason'=>null,'number_text'=>($family==='组三'?'三':($count>=4?'六':'')).$selection,'category'=>$category,'amount'=>number_format($amount*$categoryCount,2,'.',''),'count'=>$categoryCount,'play_type'=>$name,'settlement_text'=>$selection.' '.$name.' '.$category];}
        return $rows;
    }

    private function parseMultiCodeBets(string $rawOriginal,string $raw,string $category,int $lineId,float $unitStake): ?array
    {
        $specs=[];$remove=[];$selectionSourceOverride=null;
        // 兼容玩法写在号码前面的录入方式：福体组六组三 123456 各1米。
        if (preg_match($this->rules->pattern('multi_play_prefix_amount'),$raw,$prefix)) {
            preg_match_all($this->rules->pattern('multi_play_name'),(string)$prefix[1],$plays);
            foreach(array_unique($plays[0]??[]) as $play)$specs[]=['play'=>$play,'amount'=>$this->rules->amountWithUnit((float)$prefix[3],(string)($prefix[4]??''),$unitStake),'amount_value'=>(float)$prefix[3],'amount_unit'=>(string)($prefix[4]??'')];
            $selectionSourceOverride=(string)$prefix[2];
        } elseif (preg_match('/复式\s*(\d+(?:\.\d+)?)(?!\s*码)\s*(元|米|块|角|毛|倍)?\s*$/u', $raw, $singleFushi)) {
            // A concise single-play form such as “01234567复式10米” omits
            // the usual 各/每 marker. Do not let the amount become a second
            // selection, and exclude “复式三码…” catalog syntax.
            $specs[] = [
                'play' => '复式',
                'amount' => $this->rules->amountWithUnit((float)$singleFushi[1], (string)($singleFushi[2] ?? ''), $unitStake),
                'amount_value' => (float)$singleFushi[1],
                'amount_unit' => (string)($singleFushi[2] ?? ''),
            ];
            $remove[] = $singleFushi[0];
        } elseif (preg_match($this->rules->pattern('multi_play_shared_amount'),$raw,$shared)) {
            preg_match_all($this->rules->pattern('multi_play_name'),$shared[1],$plays);
            foreach(array_unique($plays[0]??[]) as $play)$specs[]=['play'=>$play,'amount'=>$this->rules->amountWithUnit((float)$shared[2],(string)($shared[3]??''),$unitStake),'amount_value'=>(float)$shared[2],'amount_unit'=>(string)($shared[3]??'')];
            $remove[]=$shared[0];
        } elseif (preg_match_all($this->rules->pattern('multi_play_amount'),$raw,$matches,PREG_SET_ORDER)) {
            foreach($matches as $match){$specs[]=['play'=>$match[1],'amount'=>$this->rules->amountWithUnit((float)$match[2],(string)($match[3]??''),$unitStake),'amount_value'=>(float)$match[2],'amount_unit'=>(string)($match[3]??'')];$remove[]=$match[0];}
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
            $specAmount=(float)$spec['amount'];
            // A multi-code 组三/组六 selection (more than three digits) is
            // priced as the site's sticky group play: one multiplier is 10
            // yuan, rather than the ordinary 2-yuan three-digit stake.
            if (($spec['amount_unit'] ?? '') === '倍'
                && strlen($uniqueSelection) > 3
                && in_array($spec['play'], ['组三','组六'], true)) {
                $specAmount=(float)($spec['amount_value'] ?? 0) * 10.0;
            }
            if(strlen($rawSelection)===3&&in_array($spec['play'],['组三','组六'],true)){
                $uniqueCount=strlen($uniqueSelection);
                if ($spec['play']==='组六' && $uniqueCount!==3) return [$this->failure($lineId,$rawOriginal,'号码形态与玩法不一致')];
                if ($spec['play']==='组三' && !in_array($uniqueCount,[2,3],true)) return [$this->failure($lineId,$rawOriginal,'号码形态与玩法不一致')];
                $identity=$spec['play']==='组三' && $uniqueCount===3 ? $this->rules->inferredCatalogPlay('组三',3) : null;
                $playName=$identity['name']??$spec['play'];
                $numberText=$identity ? '三'.$uniqueSelection : $rawSelection.'组';
                $rows[]=['id'=>$lineId,'raw_text'=>$rawOriginal,'status'=>'success','reason'=>null,'number_text'=>$numberText,'category'=>$category,'amount'=>number_format($specAmount*$categoryCount,2,'.',''),'count'=>$categoryCount,'play_type'=>$playName,'settlement_text'=>$uniqueSelection.' '.$playName.' '.$category];
                continue;
            }
            $identity=$this->rules->inferredCatalogPlay($spec['play'],strlen($uniqueSelection));
            if($identity===null)return [$this->failure($lineId,$rawOriginal,'所选数字数量与玩法不一致')];
            $numberText=match($spec['play']){
                '组三'=>'三'.$uniqueSelection,
                '组六'=>'六'.$uniqueSelection,
                '复式'=>'复'.$uniqueSelection,
                default=>'000',
            };
            $row=['id'=>$lineId,'raw_text'=>$rawOriginal,'status'=>'success','reason'=>null,'number_text'=>$numberText,'category'=>$category,'amount'=>number_format($specAmount*$categoryCount,2,'.',''),'count'=>$categoryCount,'play_type'=>$identity['name'],'settlement_text'=>$uniqueSelection.' '.$identity['name'].' '.$category];
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
        // Common wording places the amount after the complete play name:
        // “1拖34组三胆拖各1元” and “6拖01234单选全胆拖各1元”.
        if ($specs === [] && preg_match('/(?:单选全胆拖|组三胆拖|组六胆拖|组六2胆拖)\s*(?:各|每)?\s*(\d+(?:\.\d+)?)\s*(倍|注|元|米|块|角|毛)?\s*$/u', $raw, $amount)) {
            $play = str_contains($raw, '单选全胆拖') ? '单选' : (str_contains($raw, '组六2胆拖') || $double ? '组六' : (str_contains($raw, '组六') ? '组六' : '组三'));
            $specs[] = ['play'=>$play, 'amount'=>$this->rules->amountWithUnit((float)$amount[1], (string)($amount[2] ?? ''), $unitStake)];
        }
        // Natural wording puts the group name before the banker/drag pair:
        // “福体组六 9 拖 12718 各 1 倍”. For a bare “福体 9 拖 …” keep
        // compatibility with the commonly used 组六 drag default.
        if ($specs === [] && preg_match('/^\s*(?:福体|福|体)?\s*(组三|组六)?\s*\d{1,2}\s*拖\s*\d{2,9}\s*(?:各|每)\s*(\d+(?:\.\d+)?)\s*(倍|注|元|米|块|角|毛)?\s*$/u', $raw, $prefixAmount)) {
            $specs[] = [
                'play' => ($prefixAmount[1] ?? '') !== '' ? (string)$prefixAmount[1] : '组六',
                'amount' => $this->rules->amountWithUnit((float)$prefixAmount[2], (string)($prefixAmount[3] ?? ''), $unitStake),
            ];
        }
        if($specs===[])return [$this->failure($lineId,$rawOriginal,'胆拖玩法或金额不明确')];
        $rows=[];$categoryCount=$category==='福体'?2:1;
        foreach($groups as $group){$bankers=implode('',$this->uniqueDigits($group[1]));$drags=implode('',$this->uniqueDigits($group[2]));if(strlen($bankers)!==($double?2:1)||strpbrk($drags,$bankers)!==false)return [$this->failure($lineId,$rawOriginal,'胆码与拖码必须互不重复')];foreach($specs as $spec){
            if ($spec['play'] === '单选') {
                if (strlen($bankers)!==1) return [$this->failure($lineId,$rawOriginal,'单选全胆拖只支持一个胆码')];
                $identity=['category'=>'单选全胆拖','name'=>'1码拖'.strlen($drags)];
            } else {
                $identity=$this->rules->dragPlay($spec['play'],strlen($bankers),strlen($drags));
            }
            if($identity===null)return [$this->failure($lineId,$rawOriginal,'胆码或拖码数量与玩法不一致')];
            $rows[]=['id'=>$lineId,'raw_text'=>$rawOriginal,'status'=>'success','reason'=>null,'number_text'=>'胆'.$bankers.'拖'.$drags,'display_number_text'=>'胆'.$bankers.'拖'.$drags,'category'=>$category,'amount'=>number_format($spec['amount']*$categoryCount,2,'.',''),'count'=>$categoryCount,'play_type'=>$identity['name'],'settlement_text'=>$identity['category'].' 胆'.$bankers.'拖'.$drags.' '.$identity['name'].' '.$category];}}
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
        if (preg_match('/^\s*(?:福体|福|体)?\s*([0-9]{2,10})\s*(组三|组六)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?\s*$/u', $raw, $compactGroup)) {
            $family=(string)$compactGroup[2]; $selection=implode('', $this->uniqueDigits((string)$compactGroup[1])); $count=strlen($selection);
            $identity=$family==='组六'&&$count===3?['category'=>'组六','name'=>'组六','count'=>3]:$this->rules->inferredCatalogPlay($family,$count);
            if($identity===null)return $this->failure($lineId,$rawOriginal,'所选数字数量与玩法不一致');
            $playType=$identity['name']; $numberText=$family==='组三'?'三'.$selection:($family==='组六'&&$count>=4?'六'.$selection:$compactGroup[1].'组');
            $amount=$this->rules->amountWithUnit((float)$compactGroup[3],(string)($compactGroup[4]??''),$unitStake);
        } elseif (preg_match($this->rules->pattern('catalog_digit_set_bet'), $raw, $match)) {
            $selection = implode('', $this->uniqueDigits($match[1]));
            $identity = $this->rules->catalogPlay($match[2], $match[3]);
            if ($identity === null || strlen($selection) !== $identity['count']) {
                return $this->failure($lineId, $rawOriginal, '所选数字数量与玩法不一致');
            }
            $playType = $identity['name'];
            if ($match[2] === '组三') $numberText = '三'.$selection;
            if ($match[2] === '组六') $numberText = '六'.$selection;
            if ($match[2] === '组三赖') $numberText = '三赖'.$selection;
            if ($match[2] === '组六赖') $numberText = '六赖'.$selection;
            if ($match[2] === '复式') $displayNumberText = '复'.$selection;
            $amount = $this->rules->amountWithUnit((float)$match[5], (string)($match[6] ?? ''), $unitStake);
        } elseif (preg_match('/^\s*(?:福体|福|体)?\s*(组三赖|组六赖|组三|组六|复式)\s*([一二两三四五六七八九1-9])码\s*([0-9]{1,10})\s*(?:(?:各|每)\s*)?(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?\s*$/u', $raw, $prefixMatch)) {
            // Also accept the natural prefix order “组三两码12各10米”.
            $family = (string)$prefixMatch[1];
            $selection = implode('', $this->uniqueDigits((string)$prefixMatch[3]));
            $identity = $this->rules->catalogPlay($family, (string)$prefixMatch[2]);
            if ($identity === null || strlen($selection) !== $identity['count']) {
                return $this->failure($lineId, $rawOriginal, '所选数字数量与玩法不一致');
            }
            $playType = $identity['name'];
            if ($family === '组三') $numberText = '三'.$selection;
            if ($family === '组六') $numberText = '六'.$selection;
            if ($family === '组三赖') $numberText = '三赖'.$selection;
            if ($family === '组六赖') $numberText = '六赖'.$selection;
            if ($family === '复式') $displayNumberText = '复'.$selection;
            $amount = $this->rules->amountWithUnit((float)$prefixMatch[4], (string)($prefixMatch[5] ?? ''), $unitStake);
        } elseif (preg_match($this->rules->pattern('group_package_bet'), $raw, $match)) {
            $playType = $match[1];
            $numberText = $playType;
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

    /**
     * Parse a combined catalog expression such as “123复式组六各1元”.
     * 复式、组三、组六、豹子 are separate bets and must be settled separately.
     * @return ?array<int,array<string,mixed>>
     */
    private function parseCompositeCatalogBet(string $rawOriginal, string $raw, string $category, int $lineId, float $unitStake): ?array
    {
        $selection = '';
        $families = [];
        $amount = null;
        $unit = '';
        if (preg_match('/^\s*(?:福体|福|体)?\s*([0-9]{2,10})\s*(复式)\s*(组三|组六|豹子)(?:\s*(?:各|每)?\s*(\d+(?:\.\d+)?)\s*(倍|注|元|米|块|角|毛)?\s*)?$/u', $raw, $m)) {
            $selection = (string)$m[1];
            $families = ['复式', (string)$m[3]];
            $amount = ($m[4] ?? '') !== '' ? (float)$m[4] : null;
            $unit = (string)($m[5] ?? '');
        } elseif (preg_match('/^\s*(?:福体|福|体)?\s*([0-9]{2,10})\s*(组三|组六|豹子)\s*(复式)(?:\s*(?:各|每)?\s*(\d+(?:\.\d+)?)\s*(倍|注|元|米|块|角|毛)?\s*)?$/u', $raw, $m)) {
            $selection = (string)$m[1];
            $families = [(string)$m[2], '复式'];
            $amount = ($m[4] ?? '') !== '' ? (float)$m[4] : null;
            $unit = (string)($m[5] ?? '');
        } elseif (preg_match('/^\s*(?:福体|福|体)?\s*([0-9]{2,10})\s*(复式)\s*(组三|组六|豹子)\s*(?:各|每)\s*(\d+(?:\.\d+)?)\s*(倍|注|元|米|块|角|毛)\s*$/u', $raw, $m)) {
            // Kept as an explicit fallback for strings where the optional
            // amount branch above is affected by pasted whitespace.
            $selection = (string)$m[1];
            $families = ['复式', (string)$m[3]];
            $amount = (float)$m[4];
            $unit = (string)$m[5];
        } elseif (preg_match('/^\s*(?:福体|福|体)?\s*(复式)\s*(组三|组六|豹子)\s*([0-9]{2,10})\s*(?:各|每)?\s*(\d+(?:\.\d+)?)\s*(倍|注|元|米|块|角|毛)?\s*$/u', $raw, $m)) {
            $selection = (string)$m[3];
            $families = ['复式', (string)$m[2]];
            $amount = (float)$m[4];
            $unit = (string)($m[5] ?? '');
        } else {
            return null;
        }
        $digits = implode('', $this->uniqueDigits($selection));
        if (strlen($digits) < 2) return [$this->failure($lineId, $rawOriginal, '组合玩法选号数量不足')];
        if ($amount === null) return [$this->failure($lineId, $rawOriginal, '未识别到有效金额')];
        $unitAmount = $this->rules->amountWithUnit($amount, $unit, $unitStake);
        $categoryCount = $category === '福体' ? 2 : 1;
        $rows = [];
        foreach ($families as $family) {
            if ($family === '组三' && strlen($digits) < 2) return [$this->failure($lineId, $rawOriginal, '组三选号数量不足')];
            if ($family === '组六' && strlen($digits) < 3) return [$this->failure($lineId, $rawOriginal, '组六选号数量不足')];
            $playType = $family === '复式' ? (($this->rules->inferredCatalogPlay('复式', strlen($digits))['name'] ?? '复式')) : $family;
            $numberText = $family === '复式' ? '复'.$digits : ($family === '组三' ? '三'.$digits : ($family === '组六' ? '六'.$digits : '豹'.$digits));
            $rows[] = [
                'id'=>$lineId, 'raw_text'=>$rawOriginal, 'status'=>'success', 'reason'=>null,
                'number_text'=>$numberText, 'display_number_text'=>$numberText, 'category'=>$category,
                'amount'=>number_format($unitAmount*$categoryCount, 2, '.', ''), 'count'=>$categoryCount,
                'play_type'=>$playType, 'settlement_text'=>$digits.' '.$playType.' '.$category,
            ];
        }
        return $rows;
    }

    /** Parse 组三赖/组六赖 N码 using their fixed one-multiplier principal. */
    private function parseLianCatalogBet(string $rawOriginal, string $raw, string $category, int $lineId, float $unitStake): ?array
    {
        if (!preg_match('/^\s*(?:福体|福|体)?\s*([0-9]{1,10})\s*(组三赖|组六赖)\s*([一二两三四五六七1-7])码\s*(?:(?:各|每)\s*)?(\d+(?:\.\d+)?)\s*(倍|注|元|米|块|角|毛)?\s*$/u', $raw, $m)) return null;
        $selection = implode('', $this->uniqueDigits((string)$m[1]));
        $identity = $this->rules->catalogPlay((string)$m[2], (string)$m[3]);
        if ($identity === null || strlen($selection) !== $identity['count']) return [$this->failure($lineId, $rawOriginal, '所选数字数量与玩法不一致')];
        $family = (string)$m[2];
        $count = $identity['count'];
        $playKey = $family === '组三赖' ? 'Z3' : 'Z6';
        $base = $this->rules->baseStake($playKey, $count);
        $value = (float)$m[4];
        $unit = (string)($m[5] ?? '');
        $rawAmount = $unit === '倍' ? $value : $this->rules->amountWithUnit($value, $unit, $unitStake);
        $effectiveMultiplier = $unit === '倍' ? max(1.0, $value) : max(1.0, floor($rawAmount / $base + 1e-9));
        $amount = $base * $effectiveMultiplier;
        $categoryCount = $category === '福体' ? 2 : 1;
        $prefix = $family === '组三赖' ? '三赖' : '六赖';
        return [[
            'id'=>$lineId, 'raw_text'=>$rawOriginal, 'status'=>'success', 'reason'=>null,
            'number_text'=>$prefix.$selection, 'display_number_text'=>$selection, 'category'=>$category,
            'amount'=>number_format($amount*$categoryCount,2,'.',''), 'count'=>$categoryCount,
            'play_type'=>$identity['name'], 'settlement_text'=>$selection.' '.$identity['name'].' '.$category,
            'amount_type'=>$unit === '倍' ? 'multiplier' : 'money',
            'multiplier'=>$effectiveMultiplier,
            'play_key'=>$playKey.'_LIAN', 'bet_count'=>1,
        ]];
    }

    /**
     * Parse the traditional wording used on the reference sheet:
     * “沾边赖34组三1倍” / “沾边赖345组六1倍”.
     */
    private function parseStickyAliasBet(string $rawOriginal, string $raw, string $category, int $lineId, float $unitStake): ?array
    {
        // Accept all common orderings, spaces and punctuation. We extract
        // the marker, family and final amount independently instead of
        // requiring one rigid sentence layout.
        $compact = preg_replace('/[\s,，、。．.：:；;]+/u', '', $raw) ?? $raw;
        if (!preg_match('/(?:沾边赖|沾边|粘边赖|粘边|占边赖|占边)/u', $compact)) return null;
        if (!preg_match('/(组三|组六)/u', $compact, $familyMatch)) return null;
        $family = (string)$familyMatch[1];
        $selectionText = '';
        $value = 0.0;
        $unit = '';
        $beforeAmount = $compact;
        if (preg_match_all('/(倍|注|元|米|块|角|毛)/u', $compact, $unitMatches, PREG_OFFSET_CAPTURE)) {
            $unitMatch = $unitMatches[0][array_key_last($unitMatches[0])];
            $unit = (string)$unitMatch[0];
            $unitOffset = (int)$unitMatch[1];
            $left = substr($compact, 0, $unitOffset);
            if (preg_match('/(\d+)$/u', $left, $digitRun, PREG_OFFSET_CAPTURE)) {
                $run = (string)$digitRun[1][0];
                $runOffset = (int)$digitRun[1][1];
                // Split the trailing digit run into selection + amount. Try
                // the shortest positive amount first, preserving the longest
                // possible selection (e.g. 341倍 => selection34, amount1).
                for ($amountLength = 1; $amountLength <= min(3, strlen($run)); $amountLength++) {
                    $candidateValue = (float)substr($run, -$amountLength);
                    $candidateSelection = substr($run, 0, -$amountLength);
                    if ($candidateValue > 0 && strlen($candidateSelection) <= 7) {
                        $value = $candidateValue;
                        $selectionText = $candidateSelection;
                        break;
                    }
                }
                if ($value > 0) {
                    $beforeAmount = substr($compact, 0, $runOffset) . $selectionText . substr($compact, $unitOffset + strlen($unit));
                }
            }
        }
        if ($value <= 0 && preg_match('/(\d+)$/u', $compact, $tail, PREG_OFFSET_CAPTURE)) {
            $value = (float)$tail[1][0];
            $beforeAmount = substr($compact, 0, (int)$tail[1][1]) . substr($compact, (int)$tail[1][1] + strlen((string)$tail[1][0]));
        }
        if ($value <= 0) return null;
        $beforeAmount = str_replace(['福体','福','体','沾边赖','沾边','粘边赖','粘边','占边赖','占边','组三','组六'], '', $beforeAmount);
        if ($selectionText === '' && !preg_match('/\d{1,7}/', $beforeAmount, $selectionMatch)) return [$this->failure($lineId, $rawOriginal, '未识别到有效号码')];
        if ($selectionText === '') $selectionText = (string)$selectionMatch[0];
        $selection = implode('', $this->uniqueDigits($selectionText));
        $count = strlen($selection);
        $identity = $this->rules->catalogPlay($family.'赖', (string)$count);
        if ($identity === null) return [$this->failure($lineId, $rawOriginal, '所选数字数量与玩法不一致')];
        $base = $this->rules->baseStake($family === '组三' ? 'Z3' : 'Z6', $count);
        $rawAmount = $unit === '倍' ? $value : $this->rules->amountWithUnit($value, $unit, $unitStake);
        $multiplier = $unit === '倍' ? max(1.0, $value) : max(1.0, floor($rawAmount / $base + 1e-9));
        $categoryCount = $category === '福体' ? 2 : 1;
        $prefix = $family === '组三' ? '三赖' : '六赖';
        return [[
            'id'=>$lineId,'raw_text'=>$rawOriginal,'status'=>'success','reason'=>null,
            'number_text'=>$prefix.$selection,'display_number_text'=>$selection,'category'=>$category,
            'amount'=>number_format($base*$multiplier*$categoryCount,2,'.',''),'count'=>$categoryCount,
            'play_type'=>$identity['name'],'settlement_text'=>$selection.' '.$identity['name'].' '.$category,
            'amount_type'=>$unit==='倍'?'multiplier':'money','multiplier'=>$multiplier,
            'play_key'=>($family==='组三'?'Z3':'Z6').'_LIAN','bet_count'=>1,
        ]];
    }

    /** Parse a single unordered group token such as “378组各1元”. */
    private function parsePlainGroupBet(string $rawOriginal, string $raw, string $category, int $lineId, float $unitStake): ?array
    {
        if (!preg_match('/^\s*(?:福体|福|体)?\s*([0-9]{3})\s*组\s*(?:各|每)?\s*(\d+(?:\.\d+)?)\s*(倍|注|元|米|块|角|毛)?\s*$/u', $raw, $m)) return null;
        $value=(float)$m[2]; $unit=(string)($m[3]??'');
        $amount=$this->rules->amountWithUnit($value,$unit,$unitStake);
        $categoryCount=$category==='福体'?2:1;
        return [[
            'id'=>$lineId,'raw_text'=>$rawOriginal,'status'=>'success','reason'=>null,
            'number_text'=>$m[1].'组','display_number_text'=>$m[1],'category'=>$category,
            'amount'=>number_format($amount*$categoryCount,2,'.',''),'count'=>$categoryCount,
            'play_type'=>'组','settlement_text'=>$m[1].' 组 '.$category,
        ]];
    }

    /** Parse compact legacy forms such as 37810直, 37810组 and 37810直组. */
    private function parseCompactDirectGroupBet(string $rawOriginal, string $raw, string $category, int $lineId, float $unitStake): ?array
    {
        if (!preg_match('/^\s*(?:福体|福|体)?\s*(\d{3})(\d{1,4})\s*(直组|组直|直|单|组)\s*$/u', $raw, $m)) return null;
        $number=(string)$m[1];
        $stake=(float)$m[2]*$unitStake;
        $label=(string)$m[3];
        $types=in_array($label,['直组','组直'],true)?['直','组']:[($label==='单'?'直':$label)];
        $categoryCount=$category==='福体'?2:1;
        $rows=[];
        foreach($types as $type){
            $rows[]=[
                'id'=>$lineId,'raw_text'=>$rawOriginal,'status'=>'success','reason'=>null,
                'number_text'=>$number.($type==='直'?'直':'组'),'display_number_text'=>$number,
                'category'=>$category,'amount'=>number_format($stake*$categoryCount,2,'.',''),'count'=>$categoryCount,
                'stake_count'=>(int)$m[2],'code_count'=>$categoryCount,'play_type'=>$type,
                'settlement_text'=>$number.' '.$type.'各'.number_format($stake,2,'.','').'元 '.$category,
                'amount_type'=>'bet','bet_count'=>(int)$m[2],
            ];
        }
        return $rows;
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

    private function positionExpression(string $raw): string
    {
        $parts=[];
        if(preg_match_all('/(百|十|个)\s*([0-9]+)|([0-9]+)\s*(百|十|个)/u',$raw,$matches,PREG_SET_ORDER)){
            foreach($matches as $match){
                $marker=(string)($match[1]!==''?$match[1]:$match[4]);
                $digits=(string)($match[2]!==''?$match[2]:$match[3]);
                $parts[$marker]=$digits;
            }
        }
        foreach(['百','十','个'] as $marker)if(isset($parts[$marker]))$parts[$marker]=$marker.$parts[$marker];
        return $parts!==[]?implode('',$parts).'定位':trim($raw).'定位';
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
