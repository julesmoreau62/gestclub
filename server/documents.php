<?php
declare(strict_types=1);

/** Nombre entier en toutes lettres (orthographe traditionnelle, sans traits d’union obligatoires). */
function frenchNumber(int $n): string
{
    $units = ['zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze',
        'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];
    $tens = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante'];
    if ($n < 20) return $units[$n];
    if ($n < 100) {
        $ten = intdiv($n, 10);
        $unit = $n % 10;
        if ($ten === 7 || $ten === 9) {
            $base = $ten === 7 ? 'soixante' : 'quatre-vingt';
            return $base . ($ten === 7 && $unit === 1 ? ' et ' : '-') . $units[10 + $unit];
        }
        if ($ten === 8) return 'quatre-vingt' . ($unit ? '-' . $units[$unit] : 's');
        return $tens[$ten] . ($unit === 1 ? ' et un' : ($unit ? '-' . $units[$unit] : ''));
    }
    if ($n < 1000) {
        $hundred = intdiv($n, 100);
        $rest = $n % 100;
        $word = ($hundred > 1 ? $units[$hundred] . ' ' : '') . 'cent' . ($hundred > 1 && !$rest ? 's' : '');
        return $word . ($rest ? ' ' . frenchNumber($rest) : '');
    }
    if ($n < 1000000) {
        $thousand = intdiv($n, 1000);
        $rest = $n % 1000;
        $word = $thousand > 1 ? preg_replace('/(vingt|cent)s$/', '$1', frenchNumber($thousand)) . ' mille' : 'mille';
        return $word . ($rest ? ' ' . frenchNumber($rest) : '');
    }
    $million = intdiv($n, 1000000);
    $rest = $n % 1000000;
    return frenchNumber($million) . ' million' . ($million > 1 ? 's' : '') . ($rest ? ' ' . frenchNumber($rest) : '');
}

function amountInWords(int $cents): string
{
    $euros = intdiv($cents, 100);
    $rest = $cents % 100;
    $text = frenchNumber($euros) . ' euro' . ($euros > 1 ? 's' : '');
    return $text . ($rest ? ' et ' . frenchNumber($rest) . ' centime' . ($rest > 1 ? 's' : '') : '');
}

function euros(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

function frenchDate(string $iso): string
{
    $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $date = new DateTimeImmutable($iso);
    $day = (int) $date->format('j');
    return ($day === 1 ? '1er' : $day) . ' ' . $months[(int) $date->format('n') - 1] . ' ' . $date->format('Y');
}

function multiline(string $text): string
{
    return nl2br(textEscape($text), false);
}
