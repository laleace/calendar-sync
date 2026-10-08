<?php

namespace App\CalSync\Sync;

enum OperationType: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';

    public function label(): string
    {
        return match ($this) {
            self::Create => 'Crea',
            self::Update => 'Aggiorna',
            self::Delete => 'Elimina',
        };
    }
}
