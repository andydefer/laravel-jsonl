<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use AndyDefer\LaravelJsonl\Records\CacheJsonlRecord;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;

/**
 * Métadonnées extraites d'un CacheJsonlRecord
 *
 * @author Andy Defer
 */
final class CacheJsonlMetadataVO extends AbstractValueObject
{
    private readonly string $key;

    private readonly DateTimeVO $timestamp;

    private readonly ?DateTimeVO $expiresAt;

    private readonly HashLevelsVO $HashLevelsVO;

    private readonly SafeKeyVO $SafeKeyVO;

    public function __construct(CacheJsonlRecord $record, int $hashLevelCount = 2)
    {
        $this->key = $record->key;
        $this->timestamp = new DateTimeVO;
        $this->expiresAt = $record->expires_at;
        $this->HashLevelsVO = new HashLevelsVO($record->key, $hashLevelCount);
        $this->SafeKeyVO = new SafeKeyVO($record->key);
    }

    public function getKey(): string
    {
        return $this->key;
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

    public function getSafeKey(): SafeKeyVO
    {
        return $this->SafeKeyVO;
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $now = new DateTimeVO;

        return $this->expiresAt->isBefore($now);
    }

    public function getValue(): string
    {
        return $this->key;
    }
}
