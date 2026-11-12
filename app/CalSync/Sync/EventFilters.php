<?php

namespace App\CalSync\Sync;

use App\CalSync\Dto\EventDto;

/**
 * The filters of a job (sync_jobs.filters), evaluated on the original source event, before
 * privacy mode and title prefix:
 * - an event matching any "exclude" rule is skipped; exclusion wins over inclusion;
 * - with "include" rules, the event must match at least one of them, or all of them with
 *   include_mode "all";
 * - strip_prefix removes from the copy title the prefix matched by an include rule
 *   "Titolo inizia con".
 *
 * Incomplete or invalid rules are ignored (the form never saves them).
 */
final readonly class EventFilters
{
    /**
     * @param  list<FilterRule>  $include
     * @param  list<FilterRule>  $exclude
     * @param  string  $timezone  Where weekdays and start times are evaluated.
     */
    public function __construct(
        public array $include = [],
        public array $exclude = [],
        public bool $matchAll = false,
        public bool $stripPrefix = false,
        public string $timezone = 'UTC',
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data, ?string $timezone = null): self
    {
        $rules = fn (string $list): array => collect(is_array($data[$list] ?? null) ? $data[$list] : [])
            ->filter(fn ($rule) => is_array($rule))
            ->map(fn (array $rule) => FilterRule::fromArray($rule))
            ->filter()
            ->values()
            ->all();

        return new self(
            include: $rules('include'),
            exclude: $rules('exclude'),
            matchAll: ($data['include_mode'] ?? null) === 'all',
            stripPrefix: (bool) ($data['strip_prefix'] ?? false),
            timezone: $timezone ?? config('calsync.timezone'),
        );
    }

    public function count(): int
    {
        return count($this->include) + count($this->exclude);
    }

    /**
     * Why the event is filtered out, or null when it passes.
     */
    public function rejectReason(EventDto $event): ?string
    {
        foreach ($this->exclude as $rule) {
            if ($rule->matches($event, $this->timezone)) {
                return __('excluded by filter: :rule', ['rule' => $rule->describe()]);
            }
        }

        if ($this->include === []) {
            return null;
        }

        if ($this->matchAll) {
            foreach ($this->include as $rule) {
                if (! $rule->matches($event, $this->timezone)) {
                    return __('does not match filter: :rule', ['rule' => $rule->describe()]);
                }
            }

            return null;
        }

        foreach ($this->include as $rule) {
            if ($rule->matches($event, $this->timezone)) {
                return null;
            }
        }

        return count($this->include) === 1
            ? __('does not match filter: :rule', ['rule' => $this->include[0]->describe()])
            : __('matches none of the include filters');
    }

    /**
     * "[Lavoro] Riunione" → "Riunione" when strip_prefix is on and an include rule is
     * "Titolo inizia con [Lavoro]". The longest matching prefix wins, separators after it go
     * too, and a title made only of the prefix is kept as is.
     */
    public function copyTitle(string $title): string
    {
        if (! $this->stripPrefix) {
            return $title;
        }

        $trimmed = ltrim($title);
        $length = collect($this->include)->map(fn (FilterRule $rule) => $rule->prefixLength($trimmed))->filter()->max();

        if ($length === null) {
            return $title;
        }

        $rest = preg_replace('/^[\s:\-–—|·]+/u', '', mb_substr($trimmed, $length));

        return $rest === '' ? $title : $rest;
    }
}
