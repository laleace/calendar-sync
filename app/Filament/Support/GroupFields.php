<?php

namespace App\Filament\Support;

use App\Models\Group;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The "Gruppo" of accounts and jobs: form select, table column, filter and bulk assignment.
 */
final class GroupFields
{
    /** Filter value for records without a group. */
    public const NONE = 'none';

    /**
     * @return list<TextInput>
     */
    public static function schema(): array
    {
        return [
            TextInput::make('name')
                ->label('Nome del gruppo')
                ->placeholder('es. Personale')
                ->required()
                ->maxLength(50)
                ->rules([
                    // Case-insensitive on every database: "personale" and "Personale" are one group.
                    fn ($record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        $taken = Group::query()
                            ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
                            ->when($record instanceof Group, fn ($query) => $query->whereKeyNot($record->getKey()))
                            ->exists();

                        if ($taken) {
                            $fail('Esiste già un gruppo con questo nome.');
                        }
                    },
                ]),
        ];
    }

    public static function select(): Select
    {
        return Select::make('group_id')
            ->label('Gruppo')
            ->relationship('group', 'name')
            ->preload()
            ->searchable()
            ->placeholder('Nessun gruppo')
            ->createOptionForm(self::schema())
            ->editOptionForm(self::schema())
            ->helperText('Serve solo a organizzare il pannello, per esempio "Personale" o "Utente X". Con + ne crei uno nuovo.');
    }

    public static function column(): TextColumn
    {
        return TextColumn::make('group.name')
            ->label('Gruppo')
            ->badge()
            ->color('gray')
            ->placeholder('—')
            ->sortable();
    }

    /**
     * @param  string|null  $through  Relationship that carries the group, for records that
     *                                have none of their own (runs: "syncJob").
     */
    public static function filter(?string $through = null): SelectFilter
    {
        return SelectFilter::make('group')
            ->label('Gruppo')
            ->options(fn (): array => [self::NONE => 'Senza gruppo'] + Group::orderBy('name')->pluck('name', 'id')->all())
            ->query(function (Builder $query, array $data) use ($through): Builder {
                $value = $data['value'] ?? null;

                if (blank($value)) {
                    return $query;
                }

                $constrain = fn (Builder $q): Builder => $value === self::NONE
                    ? $q->whereNull($q->qualifyColumn('group_id'))
                    : $q->where($q->qualifyColumn('group_id'), $value);

                return $through === null ? $constrain($query) : $query->whereHas($through, $constrain);
            });
    }

    public static function bulkAction(): BulkAction
    {
        return BulkAction::make('assignGroup')
            ->label('Assegna gruppo')
            ->icon(Heroicon::OutlinedRectangleGroup)
            ->schema([
                Select::make('group_id')
                    ->label('Gruppo')
                    ->options(fn (): array => Group::orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder('Nessun gruppo'),
            ])
            ->action(function (BulkAction $action, Collection $records, array $data): void {
                if ($records->isEmpty()) {
                    return;
                }

                // A plain update: no model events, encrypted credentials left untouched.
                $records->toQuery()->update(['group_id' => $data['group_id'] ?? null]);
                $action->success();
            })
            ->successNotificationTitle('Gruppo assegnato')
            ->deselectRecordsAfterCompletion();
    }
}
