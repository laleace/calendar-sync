<?php

namespace App\CalSync\Sync;

use Carbon\CarbonImmutable;

/**
 * What a sync would do. Built without writing anything: a dry-run is a plan alone.
 */
final readonly class SyncPlan
{
    /**
     * @param  list<PlannedOperation>  $operations
     * @param  list<array{title: string, start: string, source: string, reason: string}>  $skipped
     * @param  list<string>  $problems  Sources that could not be read completely (no deletes for them).
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public array $operations = [],
        public int $unchanged = 0,
        public array $skipped = [],
        public array $problems = [],
    ) {}

    public function count(OperationType $type): int
    {
        return count(array_filter($this->operations, fn (PlannedOperation $op) => $op->type === $type));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function preview(int $limit = 500): array
    {
        $operations = array_map(fn (PlannedOperation $op) => $op->preview(), array_slice($this->operations, 0, $limit));
        $skipped = array_map(
            fn (array $skip) => ['op' => 'skip', 'title' => $skip['title'], 'start' => $skip['start'], 'end' => null, 'all_day' => null, 'source' => $skip['source'], 'reason' => $skip['reason']],
            array_slice($this->skipped, 0, max(0, $limit - count($operations))),
        );

        return [...$operations, ...$skipped];
    }
}
