<?php
declare(strict_types=1);
namespace app\service;
enum AmountUnit:string { case YUAN='yuan'; case MI='mi'; case KUAI='kuai'; case JIAO='jiao'; case MAO='mao'; case MULTIPLIER='multiplier'; case BET='bet'; public static function fromLabel(string $label):?self{return match($label){'元'=>self::YUAN,'米'=>self::MI,'块'=>self::KUAI,'角'=>self::JIAO,'毛'=>self::MAO,'倍'=>self::MULTIPLIER,'注'=>self::BET,default=>null};} }
