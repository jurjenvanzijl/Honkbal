<?php

namespace App\Scoring;

/**
 * Afgeleide statistieken (gemiddelden) uit tellingen.
 */
class StatFormatter
{
    /**
     * @param  array<string,int>  $s
     * @return array{avg: string, obp: string, slg: string}
     */
    public static function batting(array $s): array
    {
        $tb = $s['h1'] + 2 * $s['2b'] + 3 * $s['3b'] + 4 * $s['hr'];
        $obpDenominator = $s['ab'] + $s['bb'] + $s['hbp'] + $s['sf'];

        return [
            'avg' => self::rate($s['h'], $s['ab']),
            'obp' => self::rate($s['h'] + $s['bb'] + $s['hbp'], $obpDenominator),
            'slg' => self::rate($tb, $s['ab']),
        ];
    }

    /**
     * @param  array<string,int>  $s
     * @return array{ip: string}
     */
    public static function pitching(array $s): array
    {
        // Innings pitched in honkbalnotatie: 5.2 = 5 innings en 2 outs.
        return ['ip' => intdiv($s['outs'], 3).'.'.($s['outs'] % 3)];
    }

    /** Honkbalnotatie: .333 en 1.000 */
    private static function rate(int $num, int $den): string
    {
        if ($den === 0) {
            return '-';
        }
        $value = number_format($num / $den, 3, '.', '');

        return str_starts_with($value, '0') ? substr($value, 1) : $value;
    }
}
