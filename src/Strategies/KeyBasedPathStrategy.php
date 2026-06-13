<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Strategies;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\LaravelJsonl\Contracts\JsonlPathStrategyInterface;
use AndyDefer\LaravelJsonl\Records\CacheJsonlRecord;
use AndyDefer\LaravelJsonl\Records\CacheKeyQueryRecord;
use AndyDefer\LaravelJsonl\ValueObjects\CacheJsonlMetadataVO;
use InvalidArgumentException;

/**
 * Path strategy that organizes cache files by hashed keys.
 *
 * This strategy generates file paths based on the MD5 hash of the cache key.
 * The hash is split into multiple directory levels to avoid having too many
 * files in a single directory.
 *
 * Example: /cache/a/b/user_123.jsonl
 */
final class KeyBasedPathStrategy implements JsonlPathStrategyInterface
{
    public function __construct(
        private readonly string $basePath,
        private readonly int $hashLevels = 2,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getFilePath(AbstractRecord $entity): string
    {
        if (! $entity instanceof CacheJsonlRecord) {
            throw new InvalidArgumentException(
                sprintf('KeyBasedPathStrategy expects CacheJsonlRecord, got %s', get_class($entity))
            );
        }

        $metadata = new CacheJsonlMetadataVO($entity, $this->hashLevels);
        $safeKey = $metadata->getSafeKey()->getValue();

        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->basePath, DIRECTORY_SEPARATOR),
            $metadata->getHashLevels()->getValue(),
            $safeKey.'.jsonl',
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function getFilesToScan(AbstractRecord $query): array
    {
        if (! $query instanceof CacheKeyQueryRecord) {
            throw new InvalidArgumentException(
                sprintf('KeyBasedPathStrategy expects CacheKeyQuery, got %s', get_class($query))
            );
        }

        $tempEntity = new CacheJsonlRecord(
            key: $query->key,
            value: '',
            expires_at: null,
        );

        return [$this->getFilePath($tempEntity)];
    }

    /**
     * {@inheritDoc}
     */
    public function getBaseDirectory(): string
    {
        return $this->basePath;
    }
}
