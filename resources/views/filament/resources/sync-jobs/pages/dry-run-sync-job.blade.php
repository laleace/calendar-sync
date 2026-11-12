@php
    $run = $this->run;
    $window = $this->record->syncWindow();
    $stats = [
        [__('To create'), $run->created_count, 'success'],
        [__('To update'), $run->updated_count, 'info'],
        [__('To delete'), $run->deleted_count, 'danger'],
        [__('Skipped events'), $run->skipped_count, 'gray'],
    ];
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            {{ __('Summary') }}
        </x-slot>
        <x-slot name="description">
            {{ __('From :from to :to', ['from' => $window[0]->format(\App\Support\DateFormat::date()), 'to' => $window[1]->format(\App\Support\DateFormat::date())]) }}
            · {{ $this->record->sources()->pluck('name')->implode(', ') }} → {{ $this->record->target->name }}
            · {{ $run->message }} {{ __('No changes were made to the calendars.') }}
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
        <x-filament::callout color="danger" icon="heroicon-o-x-circle" :heading="__('Dry-run failed')" :description="$run->message" />
    @elseif ($run->errors)
        <x-filament::callout
            color="warning"
            icon="heroicon-o-exclamation-triangle"
            :heading="__('Some sources could not be read completely: to be safe, their copies will not be deleted.')"
            :description="collect($run->errors)->pluck('message')->implode(' · ')"
        />
    @endif

    {{ $this->table }}
</x-filament-panels::page>
