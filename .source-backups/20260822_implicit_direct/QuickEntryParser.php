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

        for ($index = 0; $index < $lineCount; $index++) {
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
            return $this->failure($lineId, $rawOriginal, '未识别到玩法', $numbers);
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
                $unitAmount=$unit==='倍'?(float)$match[2]*$unitStake:$this->rules->amountWithUnit((float)$match[2],$unit,$unitStake);
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
            if(strlen($rawSelection)===3&&in_array($play,['组三','组六'],true)){
                $uniqueCount=count($digits);
                if(($play==='组三'&&$uniqueCount===2)||($play==='组六'&&$uniqueCount===3)){$playType=$play;$numberText=$rawSelection;}
                elseif($play==='组三'&&$uniqueCount===3){$identity=$this->rules->inferredCatalogPlay($play,3);$playType=$identity['name'];$numberText='000';}
                else return [$this->failure($lineId,$rawOriginal,'号码形态与玩法不一致')];
            } else {
                $identity = $this->rules->inferredCatalogPlay($play, count($digits));
                if ($identity === null) return [$this->failure($lineId,$rawOriginal,'所选数字数量与玩法不一致')];
                $playType=$identity['name']; $numberText='000';
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
        if (preg_match($this->rules->pattern('size_parity_bet'), $raw, $match)) {
            $playType = in_array($match[1], ['大', '小', '单', '双'], true) ? '和'.$match[1] : $match[1];
            $amount = $this->rules->amountWithUnit((float)$match[2], (string)($match[3] ?? ''), $unitStake);
        } elseif (preg_match($this->rules->pattern('span_bet'), $raw, $match)) {
            $playType = '跨度'.$match[1];
            $amount = $this->rules->amountWithUnit((float)$match[2], (string)($match[3] ?? ''), $unitStake);
        } elseif (preg_match($this->rules->pattern('sum_bet'), $raw, $match)) {
            $playType = '和值'.$match[1];
            $amount = $this->rules->amountWithUnit((float)$match[2], (string)($match[3] ?? ''), $unitStake);
        } elseif (preg_match($this->rules->pattern('package_bet'), $raw, $match)) {
            $playType = $match[1];
            $amount = $this->rules->amountWithUnit((float)$match[2], (string)($match[3] ?? ''), $unitStake);
        }
        if ($playType === null) return null;
        $categoryCount = $category === '福体' ? 2 : 1;

        return [
            'id' => $lineId,
            'raw_text' => $rawOriginal,
            'status' => 'success',
            'reason' => null,
            'number_text' => '000',
            'category' => $category,
            'amount' => number_format($amount * $categoryCount, 2, '.', ''),
            'count' => $categoryCount,
            'play_type' => $playType,
            'settlement_text' => $playType.' '.$category,
        ];
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
        $specs=[];$remove=[];
        if (preg_match($this->rules->pattern('multi_play_shared_amount'),$raw,$shared)) {
            preg_match_all($this->rules->pattern('multi_play_name'),$shared[1],$plays);
            foreach(array_unique($plays[0]??[]) as $play)$specs[]=['play'=>$play,'amount'=>$this->rules->amountWithUnit((float)$shared[2],(string)($shared[3]??''),$unitStake)];
            $remove[]=$shared[0];
        } elseif (preg_match_all($this->rules->pattern('multi_play_amount'),$raw,$matches,PREG_SET_ORDER)) {
            foreach($matches as $match){$specs[]=['play'=>$match[1],'amount'=>$this->rules->amountWithUnit((float)$match[2],(string)($match[3]??''),$unitStake)];$remove[]=$match[0];}
        }
        if ($specs===[]) return null;
        $selectionSource=str_replace($remove,' ',$raw);
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
            $rows[]=['id'=>$lineId,'raw_text'=>$rawOriginal,'status'=>'success','reason'=>null,'number_text'=>'000','category'=>$category,'amount'=>number_format($spec['amount']*$categoryCount,2,'.',''),'count'=>$categoryCount,'play_type'=>$identity['name'],'settlement_text'=>$uniqueSelection.' '.$identity['name'].' '.$category];
        }
        $merged=[];
        foreach($rows as $row){
            if($row['number_text']==='000'){$merged[]=$row;continue;}
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
        $playType = null;
        $amount = 0.0;
        if (preg_match($this->rules->pattern('catalog_digit_set_bet'), $raw, $match)) {
            $selection = implode('', $this->uniqueDigits($match[1]));
            $identity = $this->rules->catalogPlay($match[2], $match[3]);
            if ($identity === null || strlen($selection) !== $identity['count']) {
                return $this->failure($lineId, $rawOriginal, '所选数字数量与玩法不一致');
            }
            $playType = $identity['name'];
            $amount = $this->rules->amountWithUnit((float)$match[4], (string)($match[5] ?? ''), $unitStake);
        } elseif (preg_match($this->rules->pattern('group_package_bet'), $raw, $match)) {
            $playType = $match[1];
            $amount = $this->rules->amountWithUnit((float)$match[2], (string)($match[3] ?? ''), $unitStake);
        }
        if ($playType === null) return null;
        $categoryCount = $category === '福体' ? 2 : 1;
        $settlementText = trim(($selection === '' ? '' : $selection.' ').$playType.' '.$category);
        return [
            'id'=>$lineId, 'raw_text'=>$rawOriginal, 'status'=>'success', 'reason'=>null,
            'number_text'=>'000', 'category'=>$category, 'amount'=>number_format($amount*$categoryCount,2,'.',''),
            'count'=>$categoryCount, 'play_type'=>$playType, 'settlement_text'=>$settlementText,
        ];
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

}
