<?php
declare(strict_types=1);

namespace app\service;

/**
 * Single source of truth for quick-entry text recognition.
 *
 * Keep accepted wording, aliases, regular expressions and limits here. The
 * parser is responsible only for applying these rules and building bet rows.
 */
final class QuickEntryRules
{
    public const DEFAULT_UNIT_STAKE = 2.0;
    public const MAX_TEXT_LENGTH = 10000;
    public const MAX_NUMBERS_PER_LINE = 1000;

    /** One-bet principal for the sticky/赖 group plays. */
    private const BASE_STAKE = [
        'Z6_1'=>72, 'Z6_2'=>128, 'Z6_3'=>170, 'Z6_4'=>200,
        'Z6_5'=>220, 'Z6_6'=>232, 'Z6_7'=>238,
        'Z3_1'=>36, 'Z3_2'=>68, 'Z3_3'=>96, 'Z3_4'=>120,
        'Z3_5'=>140, 'Z3_6'=>156, 'Z3_7'=>168,
    ];

    /** @var array<string, string> */
    private const PATTERNS = [
        'standalone_number_line' => '/^(?:\d{3}(?:\s+|$))+$/u',
        'number_block_continuation' => '/直|单|组|胆|拖|跨|和|飞|定位|复式|豹子/u',
        'lottery_only' => '/^(福|体|福体)$/u',
        'lottery_following_bet' => '/(?=.*\d)(?=.*(?:直|单|组|胆|拖|跨|和|飞|定位|复式|豹子))/u',
        'position_segment' => '/(?:百|十|个)\s*[0-9]/u',
        'per_unit_amount' => '/(?:各|每|个|打|下)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?/u',
        'declared_total' => '/(?:共|合计|总计)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛)?\s*(?:福体|福|体)?\s*$/u',
        'overall_total' => '/\s*合\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛)?\s*$/u',
        'overall_total_raw' => '/\s*(?:🈴|合)\s*\d+(?:\.\d+)?\s*(?:元|米|块|角|毛)?\s*$/u',
        'combined_direct_group' => '/(\d+(?:\.\d+)?)\s*倍\s*(直|单)\s*(\d+(?:\.\d+)?)\s*(?:倍)?\s*组/u',
        'combined_direct_group_short' => '/(?<!\d)(\d{1,2})\s*(直|单)\s*(\d{1,2})\s*组/u',
        'multiplier_before_play' => '/(\d+(?:\.\d+)?)\s*倍\s*(直|单|组)/u',
        'amount_after_play' => '/(直|单|组三|组六|组)\s*(\d+(?:\.\d+)?)\s*(倍|元|米|块|角|毛)?(?=\s*(?:直|单|组三|组六|组|共|合计|总计|福|体|$))/u',
        'trailing_multiplier' => '/(\d+(?:\.\d+)?)\s*倍\s*$/u',
        'trailing_short_multiplier' => '/(?<!\d)(\d{1,2})\s*(直|单|组)\s*$/u',
        'digit_set_head' => '/^\s*(?:福体|福|体)?\s*([0-9]{3,10})\s*(?:福体|福|体)?\s*(.+)$/u',
        'group_amount_first' => '/(\d+(?:\.\d+)?)\s*(元|米|块|角|毛)?\s*(组六|组6|组三|组3)/u',
        'group_play_first' => '/(组六|组6|组三|组3)\s*(?:(各|每)\s*)?(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?/u',
        'group_starts_with_play' => '/^\s*(?:组六|组6|组三|组3)/u',
        'leopard_all' => '/豹子全包/u',
        'any_play' => '/直|组|胆|拖|跨|和|单双|大小|飞|定位|复式|豹子|包|转|百|十|个/u',
        'whole_ticket_amount' => '/(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)\s*$/u',
        'position' => '/百\s*([0-9]+)\s*十\s*([0-9]+)\s*个\s*([0-9]+)/u',
        'single_position' => '/(?<!\d)(\d)\s*(百|十|个)(?!\d)/u',
        'size_parity_bet' => '/^\s*(?:福体|福|体)?\s*(?:和值\s*)?(和大|和小|和单|和双|大|小|单|双)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛)?\s*$/u',
        'size_parity_stake_bet' => '/^\s*(?:福体|福|体)?\s*(?:和值\s*)?(和大|和小|和单|和双|大|小|单|双)\s*(?:各|每)?\s*(\d+(?:\.\d+)?)\s*注\s*$/u',
        'size_parity_both_bet' => '/^\s*(?:福体|福|体)?\s*(?:和值\s*)?大小\s*(?:各|每)?\s*(\d+(?:\.\d+)?)\s*(注|元|米|块|角|毛)?\s*$/u',
        'span_bet' => '/^\s*(?:福体|福|体)?\s*跨度\s*([0-9])\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛)?\s*$/u',
        'span_prefix_bet' => '/^\s*(?:福体|福|体)?\s*([0-9])\s*跨(?:度)?\s*(\d+(?:\.\d+)?)\s*(注|元|米|块|角|毛)?\s*$/u',
        'sum_bet' => '/^\s*(?:福体|福|体)?\s*和值\s*(2[0-7]|1\d|[0-9])\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛)?\s*$/u',
        'package_bet' => '/^\s*(?:福体|福|体)?\s*(豹子全包|对子全包)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛)?\s*$/u',
        'group_package_bet' => '/^\s*(?:福体|福|体)?\s*(组三全包|组六全包)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛)?\s*$/u',
        'catalog_digit_set_bet' => '/^\s*(?:福体|福|体)?\s*([0-9]{1,10})\s*(组三赖|组六赖|组三|组六|复式)\s*([一二三四2-9])码\s*((?:各|每)\s*)?(\d+(?:\.\d+)?)\s*(元|米|块|角|毛)?\s*$/u',
        'multi_play_shared_amount' => '/((?:(?:组三|组六|复式)\s*){2,3})\s*(?:各|每)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?/u',
        'multi_play_amount' => '/(组三|组六|复式)\s*(?:各|每)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?/u',
        'multi_play_prefix_amount' => '/^\s*(?:福体|福|体)?\s*((?:(?:组三|组六|复式)\s*){2,3})([0-9]{2,10})\s*(?:各|每)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?\s*$/u',
        'multi_play_name' => '/组三|组六|复式/u',
        'digit_selection_set' => '/(?<!\d)(\d{2,10})(?!\d)/u',
        'outcome_set_suffix' => '/(和值|和|跨度|跨)\s*(?:各|每)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?\s*$/u',
        'position_values' => '/(百|十|个)\s*([0-9]+)/u',
        'single_drag_group' => '/(?<!\d)(?:胆)?(\d)\s*(?:胆)?\s*拖\s*(\d{2,9})(?!\d)/u',
        'double_drag_group' => '/(?<!\d)(\d{2})\s*(?:胆)?\s*拖\s*(\d{2,8})(?!\d)/u',
        'double_drag_marker' => '/双胆|双胆拖|组六2胆拖|(?<!\d)\d{2}\s*(?:胆)?\s*拖/u',
        'drag_play_amount' => '/(组三|组六)\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍)?(?=\s*(?:福体|福|体|$))/u',
        'sticky_group6_bet' => '/^\s*(?:福体|福|体)?\s*([0-9]{4,10})\s*组六\s*(?:(\d+(?:\.\d+)?)(?:\s*(倍|注|元|米|块|角|毛))?)?\s*$/u',
        'sticky_group3_bet' => '/^\s*(?:福体|福|体)?\s*([0-9]{4,10})\s*组三\s*(?:(\d+(?:\.\d+)?)(?:\s*(倍|注|元|米|块|角|毛))?)?\s*$/u',
        'sticky_lian6_bet' => '/^\s*(?:福体|福|体)?\s*([0-9]{1,7})\s*([一二三四五六七])码组六\s*(?:(\d+(?:\.\d+)?)(?:\s*(倍|注|元|米|块|角|毛))?)?\s*$/u',
        'sticky_lian3_bet' => '/^\s*(?:福体|福|体)?\s*([0-9]{1,7})\s*([一二三四五六七])码组三\s*(?:(\d+(?:\.\d+)?)(?:\s*(倍|注|元|米|块|角|毛))?)?\s*$/u',
        'dantuo_bet' => '/^\s*(?:福体|福|体)?\s*([0-9]{1,2})胆([0-9]{1,9})拖\s*(?:(\d+(?:\.\d+)?)(?:\s*(倍|注|元|米|块|角|毛))?)?\s*$/u',
        'double_fly_bet' => '/^\s*(?:福体|福|体)?\s*([0-9]{2})双飞\s*(?:(?:值|各值|注值)\s*)?(\d+(?:\.\d+)?)(?:\s*(倍|注|元|米|块|角|毛))?\s*$/u',
        // Do not treat the “值” character inside “和值” as a cash marker.
        'value_amount' => '/(?<!和)(?:值|各值|注值)\s*(\d+(?:\.\d+)?)/u',
        'unsupported_play' => '/包选[36]|通选|(?:三|3)同|拖拉机|奇偶|杀\s*[0-9]|组三\s*(?:跨|跨度|和|和值)|组六\s*(?:跨|跨度|和|和值)|(?:和|和值)\s*\d+\s*(?:组三|组六)/u',
        'standalone_number' => '/(?<!\d)(\d{1,3})(?!\d)/',
        'chinese_multiplier_boundary' => '/(?<=\d)(?=[零〇一二两三四五六七八九十壹贰叁肆伍陆柒捌玖]+\s*倍)/u',
        'chinese_multiplier' => '/[零〇一二两三四五六七八九十壹贰叁肆伍陆柒捌玖]+(?=\s*倍)/u',
        'chinese_digit' => '/[零〇一二两三四五六七八九壹贰叁肆伍陆柒捌玖]/u',
    ];

    /** @var array<string, string> */
    private const TEXT_ALIASES = [
        '褔' => '福', '陪' => '倍', '夸' => '跨', '垮' => '跨',
        '胯' => '跨', '挎' => '跨', '托' => '拖', '粘' => '沾',
        '黏' => '沾', '买' => ' ', '快' => '块', '🈴' => '合',
        // “复式”和“复试”是现场录入中常见的两种写法，统一到同一玩法。
        '复试' => '复式', '複式' => '复式', '複試' => '复式',
        '合大' => '和大', '合小' => '和小', '合单' => '和单', '合双' => '和双',
        '组3' => '组三', '组6' => '组六',
        '直选' => '直', '福彩' => '福', '体彩' => '体', '双打' => '福体',
        '，' => ' ', '、' => ' ', '。' => ' ', ',' => ' ', '/' => ' ', '|' => ' ', '；' => ' ', ';' => ' ',
    ];

    /** @var array<string, string> */
    private const DIGIT_ALIASES = [
        '零' => '0', '〇' => '0', '一' => '1', '壹' => '1',
        '二' => '2', '两' => '2', '贰' => '2', '三' => '3',
        '叁' => '3', '四' => '4', '肆' => '4', '五' => '5',
        '伍' => '5', '六' => '6', '陆' => '6', '七' => '7',
        '柒' => '7', '八' => '8', '捌' => '8', '九' => '9', '玖' => '9',
    ];

    /** @var array<string, int> */
    private const CHINESE_AMOUNTS = [
        '零'=>0, '〇'=>0, '一'=>1, '壹'=>1, '二'=>2, '两'=>2, '贰'=>2,
        '三'=>3, '叁'=>3, '四'=>4, '肆'=>4, '五'=>5, '伍'=>5, '六'=>6,
        '陆'=>6, '七'=>7, '柒'=>7, '八'=>8, '捌'=>8, '九'=>9, '玖'=>9,
    ];

    public function pattern(string $name): string
    {
        if (!isset(self::PATTERNS[$name])) {
            throw new \InvalidArgumentException('未知的快录识别规则：'.$name);
        }
        return self::PATTERNS[$name];
    }

    public function normalize(string $text): string
    {
        $text = strtr($text, self::TEXT_ALIASES);
        // 兼容用户在“复”和“试”之间插入空格的粘贴文本。
        $text = preg_replace('/复\s*试/u', '复式', $text) ?? $text;
        $text = preg_replace('/(?<!组)三组/u', '组三', $text) ?? $text;
        $text = preg_replace('/(?<!组)六组/u', '组六', $text) ?? $text;
        $text = preg_replace('/(?<![A-Za-z])F(?![A-Za-z])/iu', '福', $text) ?? $text;
        $text = preg_replace('/(?<![A-Za-z])T(?![A-Za-z])/iu', '体', $text) ?? $text;
        $text = preg_replace('/Z\s*6/iu', '组六', $text) ?? $text;
        $text = preg_replace('/Z\s*3/iu', '组三', $text) ?? $text;
        $text = preg_replace('/(?<![A-Za-z0-9])H\s*(2[0-7]|1\d|\d)(?![A-Za-z0-9])/iu', '和值$1', $text) ?? $text;
        $text = preg_replace('/(?<![A-Za-z0-9])K\s*([0-9])(?![A-Za-z0-9])/iu', '跨度$1', $text) ?? $text;
        $text = preg_replace('/^\s*B\s*([36])(?=\s*(?:\d|$))/iu', '包选$1 ', $text) ?? $text;
        $text = preg_replace('/(?<![A-Za-z0-9])B\s*([0-9])(?![A-Za-z0-9])/iu', '百$1', $text) ?? $text;
        $text = preg_replace('/(?<![A-Za-z0-9])S\s*([0-9])(?![A-Za-z0-9])/iu', '十$1', $text) ?? $text;
        $text = preg_replace('/(?<![A-Za-z0-9])G\s*([0-9])(?![A-Za-z0-9])/iu', '个$1', $text) ?? $text;
        $text = preg_replace('/(?:猜\s*)?1D|1\s*D|一定位/iu', '1D', $text) ?? $text;
        $text = preg_replace('/(?:猜\s*)?2D|2\s*D|二定位/iu', '2D', $text) ?? $text;
        $text = preg_replace('/(?<![A-Za-z])TX(?![A-Za-z])/iu', '通选', $text) ?? $text;
        $text = preg_replace('/通(?=\s*\d|\s*$)/u', '通选', $text) ?? $text;
        $text = preg_replace('/1D\s*(\d+(?:\.\d+)?)\s*(?:元|米|块)?/iu', '各$1元', $text) ?? $text;
        $text = preg_replace('/2D\s*(\d+(?:\.\d+)?)\s*(?:元|米|块)?/iu', '各$1元', $text) ?? $text;
        $text = preg_replace('/(?:×|[xX*])\s*(\d+(?:\.\d+)?)/u', ' $1倍', $text) ?? $text;
        $text = preg_replace('/(?<!\d)(\d{1,2})\s*胆\s*(\d{2,9})\s*拖(?!\d)/u', '$1胆拖$2', $text) ?? $text;
        $text = preg_replace('/(\d{3,9})\s*包(?:组六)?\s*(\d+(?:\.\d+)?)/u', '$1组六$2', $text) ?? $text;
        $text = preg_replace('/(\d{3,9})\s*组六\s*包\s*(\d+(?:\.\d+)?)/u', '$1组六$2', $text) ?? $text;
        $text = preg_replace('/^(\s*)(组三|组六)\s*(\d{3})\s*(\d+(?:\.\d+)?)(\s*(?:元|米|块|角|毛)?\s*)$/u', '$1$3$2$4$5', $text) ?? $text;
        $text = preg_replace('/(百十|百个|十个)\s*(\d)(\d)/u', '$1$2$3', $text) ?? $text;
        $text = preg_replace('/(?<!\d)(\d)(\d)\s*(百十|百个|十个)(?![\d])/u', '$3$1$2', $text) ?? $text;
        $text = preg_replace_callback('/(百十|百个|十个)(\d)(\d)/u', static function(array $match): string {
            $markers=preg_split('//u',$match[1],-1,PREG_SPLIT_NO_EMPTY)?:[];
            return ($markers[0]??'').$match[2].($markers[1]??'').$match[3];
        }, $text) ?? $text;
        $text = preg_replace('/^\s*((?:(?:百|十|个)\s*\d+\s*){1,3})(?:单|直)\s*(\d+(?:\.\d+)?)\s*(?:元|米|块)?\s*$/u', '$1各$2元', $text) ?? $text;
        $text = preg_replace('/^\s*((?:(?:百|十|个)\s*\d+\s*){1,3})\s+(\d+(?:\.\d+)?)\s*$/u', '$1各$2元', $text) ?? $text;
        $text = preg_replace('/^\s*(\d{3})\s+(\d+(?:\.\d+)?)\s*(元|米|块|角|毛)\s*$/u', '$1直$2$3', $text) ?? $text;
        $text = preg_replace('/^\s*((?:和值\s*(?:2[0-7]|1\d|\d)|跨度\s*\d))\s*(福体|福|体)\s*(?:各|每)?\s*(\d+(?:\.\d+)?)\s*(?:元|米|块)?\s*$/u', '$2$1$3元', $text) ?? $text;
        $text = preg_replace($this->pattern('chinese_multiplier_boundary'), ' ', $text) ?? $text;
        $text = preg_replace_callback(
            $this->pattern('chinese_multiplier'),
            fn(array $match): string => (string)$this->chineseAmount($match[0]),
            $text
        ) ?? $text;

        $protected = [
            '组六' => '组__LIU__', '组三' => '组__SAN__',
            '一码' => '__YI__码', '二码' => '__ER__码',
            '三码' => '__SAN__码', '四码' => '__SI__码',
        ];
        $text = strtr($text, $protected);
        $text = preg_replace_callback(
            $this->pattern('chinese_digit'),
            static fn(array $match): string => self::DIGIT_ALIASES[$match[0]] ?? $match[0],
            $text
        ) ?? $text;

        return strtr($text, [
            '组__LIU__' => '组六', '组__SAN__' => '组三',
            '__YI__' => '一', '__ER__' => '二', '__SAN__' => '三', '__SI__' => '四',
        ]);
    }

    public function normalizePlay(string $play): string
    {
        return $play === '单' ? '直' : $play;
    }

    public function normalizeGroupPlay(string $play): string
    {
        return in_array($play, ['组六', '组6'], true) ? '组六' : '组三';
    }

    /** @return ?array{category: string, name: string, count: int} */
    public function catalogPlay(string $family, string $countToken): ?array
    {
        $counts = ['一'=>1, '二'=>2, '两'=>2, '2'=>2, '三'=>3, '四'=>4, '3'=>3, '4'=>4, '五'=>5, '六'=>6, '七'=>7, '八'=>8, '九'=>9, '5'=>5, '6'=>6, '7'=>7, '8'=>8, '9'=>9];
        $words = [1=>'一', 2=>'二', 3=>'三', 4=>'四', 5=>'五', 6=>'六', 7=>'七', 8=>'八', 9=>'九'];
        $count = $counts[$countToken] ?? 0;
        $ranges = ['组三'=>[2,9], '组六'=>[4,9], '复式'=>[3,9], '组三赖'=>[1,7], '组六赖'=>[1,7]];
        if (!isset($ranges[$family]) || $count < $ranges[$family][0] || $count > $ranges[$family][1]) return null;
        $category = ['组三'=>'组三多码', '组六'=>'组六多码', '复式'=>'复式多码', '组三赖'=>'组三赖', '组六赖'=>'组六赖'][$family];
        $word = $family === '组三' && $count === 2 ? '两' : $words[$count];
        return ['category'=>$category, 'name'=>$family.$word.'码', 'count'=>$count];
    }

    /** @return ?array{category: string, name: string, count: int} */
    public function inferredCatalogPlay(string $family, int $count): ?array
    {
        return $this->catalogPlay($family, (string)$count);
    }

    /** @return ?array{category:string,name:string} */
    public function dragPlay(string $play,int $bankerCount,int $dragCount): ?array
    {
        if($bankerCount===1&&in_array($play,['组三','组六'],true)&&$dragCount>=2&&$dragCount<=9)return ['category'=>$play.'胆拖','name'=>'1码拖'.$dragCount];
        if($bankerCount===2&&$play==='组六'&&$dragCount>=2&&$dragCount<=8)return ['category'=>'组六2胆拖','name'=>'2码拖'.$dragCount];
        return null;
    }

    public function formatText(string $text): string
    {
        $formatted = [];
        foreach (preg_split('/\r?\n/u', trim($text)) ?: [] as $line) {
            $line = trim($this->normalize($line));
            if ($line === '') {
                if ($formatted !== [] && end($formatted) !== '') $formatted[] = '';
                continue;
            }
            $line = strtr($line, ['米' => '元', '块' => '元', '组六' => '组6', '组三' => '组3']);
            $line = preg_replace('/[ \t]+/u', ' ', $line) ?? $line;
            $formatted[] = trim($line);
        }
        while ($formatted !== [] && end($formatted) === '') array_pop($formatted);
        return implode("\n", $formatted);
    }

    public function trailingPlay(string $text): ?string
    {
        $hasDirect = str_contains($text, '直');
        $hasGroup = str_contains($text, '组');
        if ($hasDirect === $hasGroup) return null;
        return $hasDirect ? '直' : '组';
    }

    public function isPerUnitLead(string $lead): bool
    {
        return in_array($lead, ['各', '每', '个'], true);
    }

    public function amountWithUnit(float $amount, string $unit, float $unitStake): float
    {
        if ($unit === '倍') return $amount * $unitStake;
        if ($unit === '注') return $amount * $unitStake;
        return in_array($unit, ['角', '毛'], true) ? $amount / 10 : $amount;
    }

    /** Amount attached to a play; a bare number is a stake count. */
    public function playAmount(float $amount, string $unit, float $unitStake): float
    {
        return $unit === '' ? $amount * $unitStake : $this->amountWithUnit($amount, $unit, $unitStake);
    }

    public function baseStake(string $play, int $count): float
    {
        return (float)(self::BASE_STAKE[$play.'_'.$count] ?? 0);
    }

    public function category(string $text, string $lottery): string
    {
        $hasFu = str_contains($text, '福');
        $hasTi = str_contains($text, '体');
        if ($hasFu && $hasTi) return '福体';
        return $hasTi || $lottery === '排列三' ? '体' : '福';
    }

    /** @return ?array{category: string, name: string, direct: bool} */
    public function oddsIdentity(string $source): ?array
    {
        foreach (['口XX', 'X口X', 'XX口'] as $name) if (str_contains($source,$name)) return ['category'=>'一码定位','name'=>$name,'direct'=>false];
        foreach (['口口X', '口X口', 'X口口'] as $name) if (str_contains($source,$name)) return ['category'=>'二码定位','name'=>$name,'direct'=>false];
        foreach (['和大', '和小', '和单', '和双'] as $name) {
            if (str_contains($source, $name)) return ['category' => '大小单双', 'name' => '大小单双', 'direct' => true];
        }
        if (str_contains($source,'大小单双')) return ['category'=>'大小单双','name'=>'大小单双','direct'=>true];
        if (str_contains($source,'三码定位')) return ['category'=>'三码定位','name'=>'三码定位','direct'=>true];
        if (str_contains($source, '豹子全包')) return ['category' => '和值', 'name' => '豹子全包', 'direct' => false];
        if (str_contains($source, '对子全包')) return ['category' => '组六赖', 'name' => '对子全包', 'direct' => false];
        if (str_contains($source, '组三全包')) return ['category' => '组三多码', 'name' => '组三全包', 'direct' => false];
        if (str_contains($source, '组六全包')) return ['category' => '组六多码', 'name' => '组六全包', 'direct' => false];
        if (preg_match('/(组三赖|组六赖|组三|组六|复式)([一二两三四五六七八九])码/u', $source, $match)) {
            $identity = $this->catalogPlay($match[1], $match[2]);
            if ($identity !== null) return ['category'=>$identity['category'], 'name'=>$identity['name'], 'direct'=>false];
        }
        if (preg_match('/2码拖([2-8])/u',$source,$match)) return ['category'=>'组六2胆拖','name'=>'2码拖'.$match[1],'direct'=>false];
        if (preg_match('/1码拖([2-9])/u',$source,$match)) {
            $category = str_contains($source,'组三') ? '组三胆拖' : (str_contains($source,'组六') ? '组六胆拖' : (str_contains($source,'单选') ? '单选全胆拖' : null));
            if ($category !== null) return ['category'=>$category,'name'=>'1码拖'.$match[1],'direct'=>false];
        }
        if (preg_match('/跨度\s*([0-9])/u', $source, $match)) return ['category' => '跨度', 'name' => '跨度'.$match[1], 'direct' => false];
        if (preg_match('/和值\s*((?:[0-9]|1[0-3])\s*-\s*(?:1[4-9]|2[0-7]))/u',$source,$match)) return ['category'=>'和值','name'=>'和值'.str_replace(' ','',$match[1]),'direct'=>false];
        if (preg_match('/和值\s*(2[0-7]|1\d|[0-9])(?!\s*-)/u', $source, $match)) {
            $sum = (int)$match[1];
            $pair = min($sum, 27 - $sum).'-'.max($sum, 27 - $sum);
            return ['category' => '和值', 'name' => '和值'.$pair, 'direct' => false];
        }
        if (str_contains($source, '双飞')) return ['category' => '双飞', 'name' => '双飞', 'direct' => true];
        if (str_contains($source, '独胆') || str_contains($source, '胆')) return ['category' => '独胆', 'name' => '独胆', 'direct' => true];
        if (str_contains($source, '对子')) return ['category' => '对子', 'name' => '对子', 'direct' => true];
        if (str_contains($source, '组六')) return ['category' => '组六', 'name' => '组六', 'direct' => true];
        if (str_contains($source, '组三')) return ['category' => '组三', 'name' => '组三', 'direct' => true];

        preg_match_all('/[百十个]/u', $source, $positions);
        $markers = array_values(array_unique($positions[0] ?? []));
        if (count($markers) === 1) {
            $name = ['百' => '口XX', '十' => 'X口X', '个' => 'XX口'][$markers[0]];
            return ['category' => '一码定位', 'name' => $name, 'direct' => false];
        }
        if (count($markers) === 2) {
            sort($markers);
            $key = implode('', $markers);
            $name = ['十百' => '口口X', '个百' => '口X口', '个十' => 'X口口'][$key] ?? null;
            if ($name !== null) return ['category' => '二码定位', 'name' => $name, 'direct' => false];
        }
        if (count($markers) >= 3 || str_contains($source, '直')) return ['category' => '三码定位', 'name' => '三码定位', 'direct' => true];
        return null;
    }

    private function chineseAmount(string $value): int
    {
        if (str_contains($value, '十')) {
            [$left, $right] = array_pad(explode('十', $value, 2), 2, '');
            return ($left === '' ? 1 : (self::CHINESE_AMOUNTS[$left] ?? 0)) * 10
                + ($right === '' ? 0 : (self::CHINESE_AMOUNTS[$right] ?? 0));
        }

        $amount = 0;
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $amount = $amount * 10 + (self::CHINESE_AMOUNTS[$char] ?? 0);
        }
        return $amount;
    }
}
