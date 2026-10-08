<?php

namespace App\Filament\Support;

use Carbon\CarbonImmutable;

/**
 * Display helpers for the stored plan/error entries of a SyncRun.
 */
final class PlanFormatter
{
    public const OPERATIONS = [
        'create' => ['Crea', 'success'],
        'update' => ['Aggiorna', 'info'],
        'delete' => ['Elimina', 'danger'],
        'skip' => ['Salta', 'gray'],
        'read' => ['Lettura', 'warning'],
    ];

    public static function operationLabel(?string $operation): string
    {
        return self::OPERATIONS[$operation][0] ?? (string) $operation;
    }

    public static function operationColor(?string $operation): string
    {
        return self::OPERATIONS[$operation][1] ?? 'gray';
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

            return $start->format('d/m/Y').($last->isSameDay($start) ? '' : ' → '.$last->format('d/m/Y'));
        }

        $timezone = config('calsync.timezone');
        $start = CarbonImmutable::parse($entry['start'])->setTimezone($timezone);

        if (blank($entry['end'] ?? null)) {
            return $start->format('d/m/Y H:i');
        }

        $end = CarbonImmutable::parse($entry['end'])->setTimezone($timezone);

        return $start->format('d/m/Y H:i').' → '.($end->isSameDay($start) ? $end->format('H:i') : $end->format('d/m/Y H:i'));
    }
}
