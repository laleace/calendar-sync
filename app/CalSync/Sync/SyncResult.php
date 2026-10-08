<?php

namespace App\CalSync\Sync;

final class SyncResult
{
    public int $created = 0;

    public int $updated = 0;

    public int $deleted = 0;

    /** @var list<array{op: string, source: ?string, uid: ?string, title: ?string, message: string}> */
    public array $errors = [];

    /** Set when the sync stopped early (e.g. target credentials rejected). */
    public ?string $fatal = null;
}
