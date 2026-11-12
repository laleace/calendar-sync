<?php

namespace App\Support;

/**
 * Date formats shown in the GUI and on the console, chosen by the app locale.
 * Locales without an entry use ISO dates.
 */
final class DateFormat
{
    private const DATES = [
        'it' => 'd/m/Y',
    ];

    public static function date(): string
    {
        return self::DATES[app()->getLocale()] ?? 'Y-m-d';
    }

    public static function dateTime(): string
    {
        return self::date().' H:i';
    }

    public static function dateTimeWithSeconds(): string
    {
        return self::date().' H:i:s';
    }
}
