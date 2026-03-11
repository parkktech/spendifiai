<?php

namespace App\Enums;

enum ConsentRegion: string
{
    case EU = 'eu';
    case California = 'california';
    case Other = 'other';

    public function requiresExplicitOptIn(): bool
    {
        return $this === self::EU;
    }

    public function requiresOptOutNotice(): bool
    {
        return $this === self::California;
    }

    public function label(): string
    {
        return match ($this) {
            self::EU => 'European Union',
            self::California => 'California',
            self::Other => 'Other',
        };
    }
}
