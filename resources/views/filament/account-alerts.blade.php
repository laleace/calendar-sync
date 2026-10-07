@php
    use App\Enums\AccountStatus;
    use App\Filament\Resources\Accounts\AccountResource;
@endphp

@if ($accounts->isNotEmpty())
    <div style="display: grid; gap: 0.75rem;">
        @foreach ($accounts as $account)
            <x-filament::callout
                color="danger"
                icon="heroicon-o-exclamation-triangle"
                :heading="'Account “'.$account->name.'”: '.$account->status->getLabel()"
                :description="$account->status_message"
            >
                <x-slot name="footer">
                    <x-filament::link :href="AccountResource::getUrl('edit', ['record' => $account])">
                        {{ $account->status === AccountStatus::TokenExpired ? 'Riconnetti' : 'Aggiorna le credenziali' }}
                    </x-filament::link>
                </x-slot>
            </x-filament::callout>
        @endforeach
    </div>
@endif
