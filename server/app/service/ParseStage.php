<?php
declare(strict_types=1);
namespace app\service;
enum ParseStage:string { case LOTTERY='lottery'; case NUMBER='number'; case PLAY='play'; case AMOUNT='amount'; case FORMAT='format'; }
