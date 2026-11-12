@php
    use App\Enums\AccountType;
    use App\Filament\Resources\Accounts\AccountResource;
@endphp

@if ($accounts->isNotEmpty())
    <div style="display: grid; gap: 0.75rem;">
        @foreach ($accounts as $account)
            <x-filament::callout
                color="danger"
                icon="heroicon-o-exclamation-triangle"
                :heading="__('Account “:name”: :status', ['name' => $account->name, 'status' => $account->status->getLabel()])"
                :description="$account->status_message"
            >
                <x-slot name="footer">
                    @if ($account->type === AccountType::Google)
                        <x-filament::link :href="route('oauth.google.redirect', $account)">{{ __('Reconnect') }}</x-filament::link>
                    @else
                        <x-filament::link :href="AccountResource::getUrl('edit', ['record' => $account])">{{ __('Update the credentials') }}</x-filament::link>
                    @endif
                </x-slot>
            </x-filament::callout>
        @endforeach
    </div>
@endif
