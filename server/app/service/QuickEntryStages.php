<?php
declare(strict_types=1);

namespace app\service;

/** Ordered scanners used by the rewritten quick-entry pipeline. */
final class QuickEntryStages
{
    public static function lottery(string $source, string $fallback): array
    {
        $hasFu = preg_match('/福/u', $source) === 1;
        $hasTi = preg_match('/体/u', $source) === 1;
        $category = $hasFu && $hasTi ? '福体' : ($hasFu ? '福' : ($hasTi ? '体' : ($fallback === '排列三' ? '体' : '福')));
        return [$category, preg_replace('/福体|福|体/u', ' ', $source) ?? $source];
    }

    public static function lotteryCode(string $category): LotteryCode
    {
        return match($category) { '福'=>LotteryCode::FU, '体'=>LotteryCode::TI, '福体'=>LotteryCode::FU_TI, default=>LotteryCode::UNKNOWN };
    }

    public static function numbers(string $source): array
    {
        $withoutAmount = preg_replace('/(?:各|每|共|合计|总计)?\s*[\d.一二两三四五六七八九十百]+\s*(?:元|米|块|角|毛|倍|注)/u', ' ', $source) ?? $source;
        preg_match_all('/(?<!\d)(\d{1,10})(?!\d)/u', $withoutAmount, $matches);
        return array_values(array_unique($matches[1] ?? []));
    }

    public static function plays(string $source): array
    {
        preg_match_all('/组三|组六|直|单|组|复式|胆拖|胆拖|胆|双飞|飞|豹子全包|对子全包|全包|和值|跨度|转|组拖|定位/u', $source, $matches);
        return array_values(array_unique($matches[0] ?? []));
    }

    /** @param array<int,string> $plays @return array<int,PlayCode> */
    public static function playCodes(array $plays): array
    {
        $map=['直'=>PlayCode::DIRECT,'单'=>PlayCode::DIRECT,'组'=>PlayCode::GROUP,'组三'=>PlayCode::GROUP_THREE,'组六'=>PlayCode::GROUP_SIX,'复式'=>PlayCode::COMPOUND,'胆'=>PlayCode::DAN,'胆拖'=>PlayCode::DAN_TUO,'飞'=>PlayCode::FLY,'双飞'=>PlayCode::FLY,'豹子全包'=>PlayCode::LEOPARD_PACKAGE,'对子全包'=>PlayCode::PAIR_PACKAGE,'组三全包'=>PlayCode::GROUP_THREE_PACKAGE,'组六全包'=>PlayCode::GROUP_SIX_PACKAGE,'和值'=>PlayCode::SUM,'跨度'=>PlayCode::SPAN,'定位'=>PlayCode::POSITION,'转'=>PlayCode::TRANSFER,'组拖'=>PlayCode::GROUP_DRAG];
        $result=[]; foreach($plays as $play) if(isset($map[$play])&&!in_array($map[$play],$result,true))$result[]=$map[$play]; return $result;
    }

    public static function amount(string $source, float $unitStake): array
    {
        if (!preg_match('/(?:各|每|共|合计|总计)?\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍|注)/u', $source, $match)) return [null, null];
        return [(new QuickEntryRules())->amountWithUnit((float)$match[1], (string)$match[2], $unitStake), (string)$match[2]];
    }
}
