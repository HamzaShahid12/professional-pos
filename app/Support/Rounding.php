<?php

namespace App\Support;

use Brick\Math\RoundingMode;

/** Version-safe half-up rounding for brick/math (0.11 used class constants, 0.12+ uses an enum, 0.14 removed the constants). */
class Rounding
{
    public static function halfUp(): mixed
    {
        return enum_exists(RoundingMode::class)
            ? constant(RoundingMode::class.'::HalfUp')
            : constant(RoundingMode::class.'::HALF_UP');
    }
}
