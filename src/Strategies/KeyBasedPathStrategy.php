<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Strategies;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\DomainStructures\Enums\PhpType;
use AndyDefer\LaravelJsonl\Contracts\JsonlPathStrategyInterface;
use AndyDefer\LaravelJsonl\Queries\CacheKeyQueryRecord;
use AndyDefer\LaravelJsonl\Records\CacheJsonlRecord;
use AndyDefer\LaravelJsonl\ValueObjects\CacheJsonlMetadataVO;

class KeyBasedPathStrategy implements JsonlPathStrategyInterface
{
    public function __construct(
        private string $basePath,
        private int $HashLevelsVO = 2,
    ) {}

    public function getFilePath(AbstractRecord $entity): string
    {
        if (! $entity instanceof CacheJsonlRecord) {
            throw new \InvalidArgumentException(
                sprintf('KeyBasedPathStrategy expects CacheJsonlRecord, got %s', get_class($entity))
            );
        }

        $metadata = new CacheJsonlMetadataVO($entity, $this->HashLevelsVO);

        $pathParts = [rtrim($this->basePath, DIRECTORY_SEPARATOR)];
        $pathParts[] = $metadata->getHashLevels()->getValue();
        $pathParts[] = $metadata->getSafeKey()->getValue() . '.jsonl';

        return implode(DIRECTORY_SEPARATOR, $pathParts);
    }

    public function getFilesToScan(AbstractRecord $query): array
    {
        if (! $query instanceof CacheKeyQueryRecord) {
            throw new \InvalidArgumentException(
                sprintf('KeyBasedPathStrategy expects CacheKeyQuery, got %s', get_class($query))
            );
        }

        $tempEntity = new CacheJsonlRecord(
            key: $query->key,
            value: '',
            value_type: PhpType::STRING,
            expires_at: null,
        );

        return [$this->getFilePath($tempEntity)];
    }

    public function getBaseDirectory(): string
    {
        return $this->basePath;
    }
}
