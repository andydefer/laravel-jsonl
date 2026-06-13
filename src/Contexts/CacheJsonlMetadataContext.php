<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Contexts;

use AndyDefer\LaravelJsonl\Records\CacheJsonlRecord;
use AndyDefer\LaravelJsonl\ValueObjects\HashLevelsVO;
use AndyDefer\LaravelJsonl\ValueObjects\SafeKeyVO;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;

final class CacheJsonlMetadataContext
{
    private string $key;

    private SafeKeyVO $SafeKeyVO;

    private DateTimeVO $timestamp;

    private ?DateTimeVO $expiresAt;

    private HashLevelsVO $HashLevelsVO;

    public function __construct(CacheJsonlRecord $record, int $hashLevelCount = 2)
    {
        $this->key = $record->key;
        $this->SafeKeyVO = new SafeKeyVO($record->key);
        $this->timestamp = new DateTimeVO;
        $this->expiresAt = $record->expires_at;
        $this->HashLevelsVO = new HashLevelsVO($record->key, $hashLevelCount);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getSafeKey(): SafeKeyVO
    {
        return $this->SafeKeyVO;
    }

    public function getTimestamp(): DateTimeVO
    {
        return $this->timestamp;
    }

    public function getExpiresAt(): ?DateTimeVO
    {
        return $this->expiresAt;
    }

    public function getHashLevels(): HashLevelsVO
    {
        return $this->HashLevelsVO;
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $now = new DateTimeVO;

        return $this->expiresAt->isBefore($now);
    }
}
