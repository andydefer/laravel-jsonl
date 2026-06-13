<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Enums;

enum OperationType: string
{
    case IDLE = 'idle';
    case WRITING = 'writing';
    case BATCH_WRITING = 'batch_writing';
    case READING = 'reading';
    case SEARCHING = 'searching';
    case SEARCHING_MULTIPLE = 'searching_multiple';
    case CLEANING_OLDER_THAN = 'cleaning_older_than';
    case CLEANING_EXPIRED = 'cleaning_expired';
    case CLEANING_BY_PATTERN = 'cleaning_by_pattern';
    case DRY_RUN = 'dry_run';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::IDLE => 'Idle',
            self::WRITING => 'Writing',
            self::BATCH_WRITING => 'Batch Writing',
            self::READING => 'Reading',
            self::SEARCHING => 'Searching',
            self::SEARCHING_MULTIPLE => 'Searching Multiple',
            self::CLEANING_OLDER_THAN => 'Cleaning Older Than',
            self::CLEANING_EXPIRED => 'Cleaning Expired',
            self::CLEANING_BY_PATTERN => 'Cleaning By Pattern',
            self::DRY_RUN => 'Dry Run',
            self::COMPLETED => 'Completed',
            self::FAILED => 'Failed',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED]);
    }
}
