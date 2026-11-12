<?php

namespace App\CalSync\Sync;

use App\CalSync\Dto\EventDto;
use App\Enums\EventStatus;
use App\Enums\FilterField;
use App\Enums\TextOperator;
use BackedEnum;
use Carbon\CarbonImmutable;

/**
 * One condition of a job filter, evaluated on the original source event.
 *
 * Stored shapes:
 * - text: {"field": "title", "operator": "starts_with", "value": "[Lavoro]"}, case-insensitive;
 * - {"field": "weekday", "weekdays": [1, 2, 3, 4, 5]}, ISO days (1 = Monday) of the start;
 * - {"field": "start_time", "from": "09:00", "to": "18:00"}: "to" excluded, from > to spans
 *   midnight, all-day events never match;
 * - flags, with no other key: {"field": "all_day"}.
 */
final readonly class FilterRule
{
    /**
     * ISO day number => short name in the app locale (lun, mar… / Mon, Tue…).
     *
     * @return array<int, string>
     */
    public static function weekdays(): array
    {
        $monday = CarbonImmutable::create(2024, 1, 1)->locale(app()->getLocale());

        return collect(range(1, 7))->mapWithKeys(fn (int $day) => [$day => $monday->addDays($day - 1)->shortDayName])->all();
    }

    /**
     * @param  list<int>  $weekdays
     */
    private function __construct(
        public FilterField $field,
        public ?TextOperator $operator = null,
        public ?string $value = null,
        public array $weekdays = [],
        public ?int $fromMinute = null,
        public ?int $toMinute = null,
    ) {}

    /**
     * Null when the rule is incomplete or invalid, like a half-filled form row.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $field = FilterField::tryFrom(self::scalar($data['field'] ?? null));

        return match (true) {
            $field === null => null,
            $field->isText() => self::text($field, $data),
            $field === FilterField::Weekday => self::weekday($data),
            $field === FilterField::StartTime => self::startTime($data),
            default => new self($field),
        };
    }

    /**
     * The user writes the bare expression, e.g. ^\[(Lavoro|Work)\]: delimiters and flags are
     * added when compiling.
     */
    public static function isValidPattern(string $pattern): bool
    {
        return @preg_match(self::compile($pattern), '') !== false;
    }

    /**
     * "09:00" or "09:00:00" → 540.
     */
    public static function minuteOfDay(mixed $time): ?int
    {
        if (! is_string($time) || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $time, $m)) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    public function matches(EventDto $event, string $timezone): bool
    {
        return match ($this->field) {
            FilterField::Title => $this->matchesText($event->title),
            FilterField::Location => $this->matchesText($event->location ?? ''),
            FilterField::Description => $this->matchesText($event->description ?? ''),
            FilterField::Weekday => in_array((int) $this->localStart($event, $timezone)->format('N'), $this->weekdays, true),
            FilterField::StartTime => ! $event->allDay && $this->inTimeRange($this->localStart($event, $timezone)),
            FilterField::AllDay => $event->allDay,
            FilterField::Free => $event->transparent,
            FilterField::Tentative => $event->status === EventStatus::Tentative,
            FilterField::Recurring => $event->recurrenceId !== null,
        };
    }

    /**
     * Length of the prefix this rule removes from a (left-trimmed) title: only a matching
     * "Titolo inizia con".
     */
    public function prefixLength(string $title): ?int
    {
        if ($this->field !== FilterField::Title || $this->operator !== TextOperator::StartsWith || mb_stripos($title, $this->value) !== 0) {
            return null;
        }

        return mb_strlen($this->value);
    }

    public function describe(): string
    {
        $label = $this->field->getLabel();
        $weekdays = self::weekdays();

        return match (true) {
            $this->operator === TextOperator::Regex => __(':field matches /:pattern/', ['field' => $label, 'pattern' => $this->value]),
            $this->operator !== null => __(':field :operator ":value"', ['field' => $label, 'operator' => $this->operator->getLabel(), 'value' => $this->value]),
            $this->field === FilterField::Weekday => __('Day: :days', ['days' => implode(', ', array_map(fn (int $day) => $weekdays[$day], $this->weekdays))]),
            $this->field === FilterField::StartTime => __('Starts between :from and :to', ['from' => self::clock($this->fromMinute), 'to' => self::clock($this->toMinute)]),
            default => $label,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function text(FilterField $field, array $data): ?self
    {
        $operator = TextOperator::tryFrom(self::scalar($data['operator'] ?? null));
        $value = trim(self::scalar($data['value'] ?? null));

        if ($operator === null || $value === '' || ($operator === TextOperator::Regex && ! self::isValidPattern($value))) {
            return null;
        }

        return new self($field, $operator, $value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function weekday(array $data): ?self
    {
        $days = collect(is_array($data['weekdays'] ?? null) ? $data['weekdays'] : [])
            ->map(fn ($day) => (int) self::scalar($day))
            ->filter(fn (int $day) => $day >= 1 && $day <= 7)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $days === [] ? null : new self(FilterField::Weekday, weekdays: $days);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function startTime(array $data): ?self
    {
        $from = self::minuteOfDay($data['from'] ?? null);
        $to = self::minuteOfDay($data['to'] ?? null);

        return $from === null || $to === null || $from === $to
            ? null
            : new self(FilterField::StartTime, fromMinute: $from, toMinute: $to);
    }

    private static function compile(string $pattern): string
    {
        // Escape the unescaped delimiters, so that "a/b" works as typed.
        return '/'.preg_replace('~\\\\.(*SKIP)(*FAIL)|/~s', '\\/', $pattern).'/iu';
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    private static function clock(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    private function matchesText(string $text): bool
    {
        $text = trim($text);

        return match ($this->operator) {
            TextOperator::Contains => mb_stripos($text, $this->value) !== false,
            TextOperator::StartsWith => mb_stripos($text, $this->value) === 0,
            TextOperator::EndsWith => mb_strtolower(mb_substr($text, -mb_strlen($this->value))) === mb_strtolower($this->value),
            TextOperator::Equals => mb_strtolower($text) === mb_strtolower($this->value),
            // A runtime failure (e.g. backtrack limit) counts as no match.
            TextOperator::Regex => preg_match(self::compile($this->value), $text) === 1,
        };
    }

    /**
     * All-day events keep their date as is: converting midnight could move them to another day.
     */
    private function localStart(EventDto $event, string $timezone): CarbonImmutable
    {
        return $event->allDay ? $event->start : $event->start->setTimezone($timezone);
    }

    private function inTimeRange(CarbonImmutable $start): bool
    {
        $minute = $start->hour * 60 + $start->minute;

        return $this->fromMinute < $this->toMinute
            ? $minute >= $this->fromMinute && $minute < $this->toMinute
            : $minute >= $this->fromMinute || $minute < $this->toMinute;
    }
}
