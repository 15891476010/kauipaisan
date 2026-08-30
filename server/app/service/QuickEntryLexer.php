<?php
declare(strict_types=1);
namespace app\service;

/** Lossless lexer for quick-entry text. It never calculates or rewrites bets. */
final class QuickEntryLexer
{
    public function __construct(private readonly QuickEntryRules $rules = new QuickEntryRules()) {}

    /** @return array<int,QuickEntryToken> */
    public function tokenize(string $source): array
    {
        $normalized = $this->rules->normalize($source);
        $tokens = [];
        $occupied = [];
        $add = static function(string $kind, string $value, int $offset, int $length) use (&$tokens, &$occupied): void {
            for ($i = $offset; $i < $offset + $length; $i++) if (isset($occupied[$i])) return;
            for ($i = $offset; $i < $offset + $length; $i++) $occupied[$i] = true;
            $tokens[] = new QuickEntryToken($kind, $value, $offset, $length);
        };
        preg_match_all('/(?:各|每|共|合计|总计)?\s*\d+(?:\.\d+)?\s*(?:元|米|块|角|毛|倍|注)/u', $normalized, $amounts, PREG_OFFSET_CAPTURE);
        foreach ($amounts[0] ?? [] as $match) $add('amount', (string)$match[0], (int)$match[1], strlen((string)$match[0]));
        preg_match_all('/(?:福体|福|体)/u', $normalized, $lotteries, PREG_OFFSET_CAPTURE);
        foreach ($lotteries[0] ?? [] as $match) $add('lottery', (string)$match[0], (int)$match[1], strlen((string)$match[0]));
        $playPattern = '/组三全包|组六全包|豹子全包|对子全包|单选全胆拖|全胆拖|组三|组六|复式|直|单|组|独胆|胆|胆拖|双飞|飞|跨度|跨|和值|和大|和小|和单|和双|转|组拖|定位/u';
        preg_match_all($playPattern, $normalized, $plays, PREG_OFFSET_CAPTURE);
        foreach ($plays[0] ?? [] as $match) $add('play', (string)$match[0], (int)$match[1], strlen((string)$match[0]));
        preg_match_all('/(?<!\d)\d{1,10}(?!\d)/u', $normalized, $numbers, PREG_OFFSET_CAPTURE);
        foreach ($numbers[0] ?? [] as $match) $add('number', (string)$match[0], (int)$match[1], strlen((string)$match[0]));
        usort($tokens, static fn(QuickEntryToken $a, QuickEntryToken $b): int => $a->offset <=> $b->offset);
        return $tokens;
    }

    /** @return array<int,array{raw:string,tokens:array<int,QuickEntryToken>}> */
    public function lines(string $source): array
    {
        $result = [];
        foreach (preg_split('/\r?\n/u', $source) ?: [] as $line) $result[] = ['raw' => (string)$line, 'tokens' => $this->tokenize((string)$line)];
        return $result;
    }
}
