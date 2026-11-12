<?php

namespace App\Filament\Support;

use App\Support\DateFormat;
use Carbon\CarbonImmutable;

/**
 * Display helpers for the stored plan/error entries of a SyncRun.
 */
final class PlanFormatter
{
    private const COLORS = [
        'create' => 'success',
        'update' => 'info',
        'delete' => 'danger',
        'skip' => 'gray',
        'read' => 'warning',
    ];

    /**
     * Labels of the operations a plan can contain ("read" only appears among errors).
     *
     * @return array<string, string>
     */
    public static function operations(): array
    {
        return [
            'create' => __('Create'),
            'update' => __('Update'),
            'delete' => __('Delete'),
            'skip' => __('Skip'),
            'read' => __('Read'),
        ];
    }

    public static function operationLabel(?string $operation): string
    {
        return self::operations()[$operation] ?? (string) $operation;
    }

    public static function operationColor(?string $operation): string
    {
        return self::COLORS[$operation] ?? 'gray';
    }

    /**
     * "14/10/2026 18:30 → 20:00", "24/10/2026 → 26/10/2026" (all-day, inclusive).
     *
     * @param  array<string, mixed>  $entry
     */
    public static function when(array $entry): string
    {
        if (blank($entry['start'] ?? null)) {
            return '—';
        }

        if ($entry['all_day'] ?? false) {
            $start = CarbonImmutable::parse($entry['start']);
            $last = filled($entry['end'] ?? null) ? CarbonImmutable::parse($entry['end'])->subDay() : $start;

            return $start->format(DateFormat::date()).($last->isSameDay($start) ? '' : ' → '.$last->format(DateFormat::date()));
        }

        $timezone = config('calsync.timezone');
        $start = CarbonImmutable::parse($entry['start'])->setTimezone($timezone);

        if (blank($entry['end'] ?? null)) {
            return $start->format(DateFormat::dateTime());
        }

        $end = CarbonImmutable::parse($entry['end'])->setTimezone($timezone);

        return $start->format(DateFormat::dateTime()).' → '.($end->isSameDay($start) ? $end->format('H:i') : $end->format(DateFormat::dateTime()));
    }
}
