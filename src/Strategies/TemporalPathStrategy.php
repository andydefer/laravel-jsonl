<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Strategies;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\LaravelJsonl\Contracts\JsonlPathStrategyInterface;
use AndyDefer\LaravelJsonl\Records\LogJsonlRecord;
use AndyDefer\LaravelJsonl\Records\TemporalLogQueryRecord;
use AndyDefer\LaravelJsonl\ValueObjects\LogJsonlMetadataVO;

class TemporalPathStrategy implements JsonlPathStrategyInterface
{
    public function __construct(
        private string $basePath,
    ) {}

    public function getFilePath(AbstractRecord $entity): string
    {
        if (! $entity instanceof LogJsonlRecord) {
            throw new \InvalidArgumentException(
                sprintf('TemporalPathStrategy expects LogJsonlRecord, got %s', get_class($entity))
            );
        }

        $metadata = new LogJsonlMetadataVO($entity);

        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->basePath, DIRECTORY_SEPARATOR),
            $metadata->getDate(),
            $metadata->getHour().'.jsonl',
        ]);
    }

    public function getFilesToScan(AbstractRecord $query): array
    {
        if (! $query instanceof TemporalLogQueryRecord) {
            throw new \InvalidArgumentException(
                sprintf('TemporalPathStrategy expects TemporalLogQuery, got %s', get_class($query))
            );
        }

        $files = [];
        $current = $query->from->toDateTimeImmutable();
        $end = $query->to->toDateTimeImmutable();

        while ($current <= $end) {
            $date = $current->format('Y-m-d');
            $path = implode(DIRECTORY_SEPARATOR, [rtrim($this->basePath, DIRECTORY_SEPARATOR), $date]);

            for ($hour = 0; $hour <= 23; $hour++) {
                $hourStr = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
                $files[] = implode(DIRECTORY_SEPARATOR, [$path, $hourStr.'.jsonl']);
            }

            $current = $current->modify('+1 day');
        }

        return $files;
    }

    public function getBaseDirectory(): string
    {
        return $this->basePath;
    }
}
