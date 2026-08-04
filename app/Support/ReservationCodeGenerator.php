<?php

namespace App\Support;

final class ReservationCodeGenerator
{
    private const CHARSET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(): string
    {
        return 'CHK-'.self::randomSegment(5).'-'.self::randomSegment(5);
    }

    private static function randomSegment(int $length): string
    {
        $segment = '';
        $max = strlen(self::CHARSET) - 1;

        for ($i = 0; $i < $length; $i++) {
            $segment .= self::CHARSET[random_int(0, $max)];
        }

        return $segment;
    }
}
