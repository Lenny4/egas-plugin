<?php

namespace Egas\utils;

final class RoundUtils
{
    public static function round(int|float|string $value): float
    {
        return round((float)$value * 100) / 100;
    }
}
