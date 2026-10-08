@php
    $run = $this->run;
    $window = $this->record->syncWindow();
    $stats = [
        ['Da creare', $run->created_count, 'success'],
        ['Da aggiornare', $run->updated_count, 'info'],
        ['Da eliminare', $run->deleted_count, 'danger'],
        ['Saltati', $run->skipped_count, 'gray'],
    ];
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            Riepilogo
        </x-slot>
        <x-slot name="description">
            Periodo dal {{ $window[0]->format('d/m/Y') }} al {{ $window[1]->format('d/m/Y') }}
            · {{ $this->record->sources()->pluck('name')->implode(', ') }} → {{ $this->record->target->name }}
            · {{ $run->message }} Nessuna modifica è stata fatta ai calendari.
        </x-slot>

        <div style="display: flex; flex-wrap: wrap; gap: 0.75rem;">
            @foreach ($stats as [$label, $count, $color])
                <x-filament::badge :color="$color" size="lg">
                    {{ $label }}: {{ $count }}
                </x-filament::badge>
            @endforeach
        </div>
    </x-filament::section>

    @if ($run->status === \App\Enums\SyncRunStatus::Failed)
        <x-filament::callout color="danger" icon="heroicon-o-x-circle" heading="Dry-run non riuscito" :description="$run->message" />
    @elseif ($run->errors)
        <x-filament::callout
            color="warning"
            icon="heroicon-o-exclamation-triangle"
            heading="Alcune sorgenti non sono state lette completamente: per sicurezza le loro copie non verranno eliminate."
            :description="collect($run->errors)->pluck('message')->implode(' · ')"
        />
    @endif

    {{ $this->table }}
</x-filament-panels::page>
