<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AccountType: string implements HasLabel
{
    case IcloudCaldav = 'icloud_caldav';
    case CaldavGeneric = 'caldav_generic';
    case Google = 'google';

    public function getLabel(): string
    {
        return match ($this) {
            self::IcloudCaldav => 'iCloud (CalDAV)',
            self::CaldavGeneric => 'CalDAV generico',
            self::Google => 'Google',
        };
    }

    public function isCalDav(): bool
    {
        return $this !== self::Google;
    }
}
