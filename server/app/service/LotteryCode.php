<?php
declare(strict_types=1);
namespace app\service;
enum LotteryCode:string { case FU='fu'; case TI='ti'; case FU_TI='fu_ti'; case UNKNOWN='unknown'; public function label():string{return match($this){self::FU=>'福',self::TI=>'体',self::FU_TI=>'福体',self::UNKNOWN=>''};} }
