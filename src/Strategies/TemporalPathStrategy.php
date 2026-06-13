<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Strategies;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\LaravelJsonl\Contracts\JsonlPathStrategyInterface;
use AndyDefer\LaravelJsonl\Records\LogJsonlRecord;
use AndyDefer\LaravelJsonl\Records\TemporalLogQueryRecord;
use AndyDefer\LaravelJsonl\ValueObjects\LogJsonlMetadataVO;
use InvalidArgumentException;

/**
 * Path strategy that organizes log files by date and hour.
 *
 * This strategy generates file paths based on the timestamp of log entries.
 * Logs are stored in a hierarchical structure: year/month/day/hour.
 *
 * Example path structure:
 * /logs/structured/2026-01-15/14.jsonl
 *
 * @author Andy Defer
 */
final class TemporalPathStrategy implements JsonlPathStrategyInterface
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getFilePath(AbstractRecord $entity): string
    {
        $this->validateEntity($entity);

        $metadata = new LogJsonlMetadataVO($entity);

        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->basePath, DIRECTORY_SEPARATOR),
            $metadata->getDate(),
            $metadata->getHour().'.jsonl',
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function getFilesToScan(AbstractRecord $query): array
    {
        $this->validateQuery($query);
        /** @var TemporalLogQueryRecord $query */
        $files = [];
        $current = $query->from->toDateTimeImmutable();
        $end = $query->to->toDateTimeImmutable();

        while ($current <= $end) {
            $date = $current->format('Y-m-d');
            $dayPath = $this->buildDayPath($date);

            $files = array_merge($files, $this->buildHourlyFilePaths($dayPath));

            $current = $current->modify('+1 day');
        }

        return $files;
    }

    /**
     * {@inheritDoc}
     */
    public function getBaseDirectory(): string
    {
        return $this->basePath;
    }

    /**
     * Validates that the entity is a LogJsonlRecord.
     *
     * @throws InvalidArgumentException If the entity type is invalid
     */
    private function validateEntity(AbstractRecord $entity): void
    {
        if (! $entity instanceof LogJsonlRecord) {
            throw new InvalidArgumentException(
                sprintf('TemporalPathStrategy expects LogJsonlRecord, got %s', get_class($entity))
            );
        }
    }

    /**
     * Validates that the query is a TemporalLogQueryRecord.
     *
     * @throws InvalidArgumentException If the query type is invalid
     */
    private function validateQuery(AbstractRecord $query): void
    {
        if (! $query instanceof TemporalLogQueryRecord) {
            throw new InvalidArgumentException(
                sprintf('TemporalPathStrategy expects TemporalLogQuery, got %s', get_class($query))
            );
        }
    }

    /**
     * Builds the directory path for a specific date.
     *
     * @param  string  $date  Date in Y-m-d format
     * @return string Full directory path
     */
    private function buildDayPath(string $date): string
    {
        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->basePath, DIRECTORY_SEPARATOR),
            $date,
        ]);
    }

    /**
     * Generates all hourly file paths for a given day.
     *
     * @param  string  $dayPath  Directory path for the day
     * @return array<string> Array of 24 file paths (00.jsonl to 23.jsonl)
     */
    private function buildHourlyFilePaths(string $dayPath): array
    {
        $files = [];

        for ($hour = 0; $hour <= 23; $hour++) {
            $hourStr = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
            $files[] = implode(DIRECTORY_SEPARATOR, [$dayPath, $hourStr.'.jsonl']);
        }

        return $files;
    }
}
