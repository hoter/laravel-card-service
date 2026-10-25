<?php

namespace App\Services\Support;

final class Vector
{
    public static function literal(array $vector): string
    {
        return '['.implode(',', array_map(static fn ($value) => sprintf('%.7F', (float) $value), $vector)).']';
    }
}
