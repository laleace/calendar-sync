<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AccountStatus: string implements HasColor, HasLabel
{
    case Unknown = 'unknown';
    case Ok = 'ok';
    case AuthFailed = 'auth_failed';
    case TokenExpired = 'token_expired';
    case Error = 'error';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unknown => 'Non verificato',
            self::Ok => 'OK',
            self::AuthFailed => 'Credenziali non valide',
            self::TokenExpired => 'Token scaduto',
            self::Error => 'Errore',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unknown => 'gray',
            self::Ok => 'success',
            self::AuthFailed, self::TokenExpired => 'danger',
            self::Error => 'warning',
        };
    }

    /**
     * Statuses that require the user to re-enter credentials or reconnect.
     */
    public function needsAttention(): bool
    {
        return in_array($this, [self::AuthFailed, self::TokenExpired], true);
    }
}
