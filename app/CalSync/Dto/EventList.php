<?php

namespace App\CalSync\Dto;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Events read from a calendar, plus the resources that could not be read.
 *
 * A list with problems is incomplete: the sync engine must not delete target copies
 * based on it, since a missing event may just be one that failed to parse.
 *
 * @implements IteratorAggregate<int, EventDto>
 */
final readonly class EventList implements Countable, IteratorAggregate
{
    /**
     * @param  list<EventDto>  $events
     * @param  list<string>  $problems
     */
    public function __construct(
        public array $events = [],
        public array $problems = [],
    ) {}

    public function isComplete(): bool
    {
        return $this->problems === [];
    }

    public function merge(self $other): self
    {
        return new self([...$this->events, ...$other->events], [...$this->problems, ...$other->problems]);
    }

    public function sorted(): self
    {
        $events = $this->events;
        usort($events, fn (EventDto $a, EventDto $b) => [$a->start->getTimestamp(), $a->title] <=> [$b->start->getTimestamp(), $b->title]);

        return new self($events, $this->problems);
    }

    public function count(): int
    {
        return count($this->events);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->events);
    }
}
