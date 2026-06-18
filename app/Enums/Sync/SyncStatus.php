<?php

namespace App\Enums\Sync;

enum SyncStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    /**
     * All backing values — handy for migration enum columns and validation.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * PrimeVue Tag severity for this status (used by the UI).
     */
    public function severity(): string
    {
        return match ($this) {
            self::COMPLETED => 'success',
            self::FAILED => 'danger',
            self::RUNNING => 'info',
            self::PENDING => 'secondary',
        };
    }

    /**
     * Whether this is a terminal (no longer changing) state.
     */
    public function isFinished(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED], true);
    }
}
