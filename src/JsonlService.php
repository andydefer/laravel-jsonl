<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\LaravelJsonl\Contexts\JsonlProcessingContext;
use AndyDefer\LaravelJsonl\Contracts\JsonlCleanerInterface;
use AndyDefer\LaravelJsonl\Contracts\JsonlLockInterface;
use AndyDefer\LaravelJsonl\Contracts\JsonlPathStrategyInterface;
use AndyDefer\LaravelJsonl\Contracts\JsonlReaderInterface;
use AndyDefer\LaravelJsonl\Contracts\JsonlWriterInterface;
use AndyDefer\LaravelJsonl\Enums\OperationType;
use AndyDefer\LaravelJsonl\Exceptions\JsonlException;
use AndyDefer\LaravelJsonl\Exceptions\JsonlLockException;
use AndyDefer\LaravelJsonl\Records\CacheJsonlRecord;
use AndyDefer\LaravelJsonl\Records\LogJsonlRecord;
use AndyDefer\LaravelJsonl\ValueObjects\CacheJsonlMetadataVO;
use AndyDefer\LaravelJsonl\ValueObjects\CacheValueVO;
use AndyDefer\LaravelJsonl\ValueObjects\JsonlLockVO;
use AndyDefer\PhpServices\Contracts\FileSystemInterface;
use AndyDefer\PhpServices\Enums\PermissionMode;
use Throwable;

/**
 * Main service for JSONL (JSON Lines) storage operations.
 *
 * Provides write, read, search, clean, and lock capabilities for JSONL files.
 * Supports buffered writes, concurrent access with file locking, and
 * configurable path strategies for different use cases (logs, cache, etc.).
 *
 * @author Andy Defer
 */
final class JsonlService implements JsonlCleanerInterface, JsonlLockInterface, JsonlReaderInterface, JsonlWriterInterface
{
    /** @var array<string, JsonlLockVO> */
    private array $locks = [];

    /** @var array<string, array<AbstractRecord>> */
    private array $buffer = [];

    private int $bufferSize = 0;

    /** @var callable(string, int):void|null */
    private $onFlushCallback = null;

    /**
     * @param  JsonlPathStrategyInterface  $pathStrategy  Strategy for file path generation
     * @param  FileSystemInterface  $fileSystem  File system operations
     * @param  int|null  $defaultBufferSize  Default buffer size (null = disabled)
     * @param  PermissionMode  $directoryPermission  Permission mode for created directories
     */
    public function __construct(
        private JsonlPathStrategyInterface $pathStrategy,
        private readonly FileSystemInterface $fileSystem,
        private readonly ?int $defaultBufferSize = null,
        private readonly PermissionMode $directoryPermission = PermissionMode::DIRECTORY,
    ) {
        if ($defaultBufferSize !== null && $defaultBufferSize > 0) {
            $this->enableBuffer($defaultBufferSize);
        }
    }

    // ============================================================
    // JsonlWriterInterface Implementation
    // ============================================================

    /**
     * {@inheritDoc}
     */
    public function write(AbstractRecord $entity, bool $lock = true, ?JsonlProcessingContext $context = null): void
    {
        $context ??= new JsonlProcessingContext;
        $context->setCurrentOperation(OperationType::WRITING);

        try {
            $filePath = $this->pathStrategy->getFilePath($entity);
            $data = $this->prepareDataForWrite($entity);
            $jsonLine = $this->encodeToJsonLine($data);

            $this->ensureDirectoryExists($filePath);

            $writeOperation = function () use ($filePath, $jsonLine, $context): void {
                $this->fileSystem->append($filePath, $jsonLine);
                $context->addWrittenLines($filePath, 1);
                $context->addProcessedFile($filePath);
            };

            if ($lock) {
                $this->executeWithLock($filePath, $writeOperation);
            } else {
                $writeOperation();
            }

            $context->complete();
        } catch (Throwable $e) {
            $context->setLastError($e->getMessage());
            throw new JsonlException($e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function writeBatch(array $entities, bool $lock = true, ?JsonlProcessingContext $context = null): void
    {
        if (empty($entities)) {
            return;
        }

        $context ??= new JsonlProcessingContext;
        $context->setCurrentOperation(OperationType::BATCH_WRITING);

        try {
            $firstEntity = $entities[0];
            $filePath = $this->pathStrategy->getFilePath($firstEntity);

            $this->ensureDirectoryExists($filePath);

            $writeOperation = function () use ($filePath, $entities, $context): void {
                $content = '';

                foreach ($entities as $entity) {
                    $data = $this->prepareDataForWrite($entity);
                    $content .= $this->encodeToJsonLine($data);
                }

                $this->fileSystem->append($filePath, $content);
                $context->addWrittenLines($filePath, count($entities));
                $context->addProcessedFile($filePath);
            };

            if ($lock) {
                $this->executeWithLock($filePath, $writeOperation);
            } else {
                $writeOperation();
            }

            $context->complete();
        } catch (Throwable $e) {
            $context->setLastError($e->getMessage());
            throw new JsonlException($e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function writeBuffered(AbstractRecord $entity, ?JsonlProcessingContext $context = null): void
    {
        if ($this->bufferSize === 0) {
            $this->write($entity, true, $context);

            return;
        }

        $filePath = $this->pathStrategy->getFilePath($entity);

        if (! isset($this->buffer[$filePath])) {
            $this->buffer[$filePath] = [];
        }

        $this->buffer[$filePath][] = $entity;

        if (count($this->buffer[$filePath]) >= $this->bufferSize) {
            $this->flushBuffer($filePath, $context);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function flushBuffer(?string $filePath = null, ?JsonlProcessingContext $context = null): void
    {
        $context ??= new JsonlProcessingContext;

        if ($filePath !== null) {
            $this->flushSingleBuffer($filePath, $context);

            return;
        }

        foreach (array_keys($this->buffer) as $path) {
            $this->flushSingleBuffer($path, $context);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function enableBuffer(int $size = 100): void
    {
        $this->bufferSize = $size;
    }

    /**
     * {@inheritDoc}
     */
    public function disableBuffer(): void
    {
        $this->flushBuffer();
        $this->bufferSize = 0;
    }

    /**
     * {@inheritDoc}
     */
    public function onFlush(callable $callback): void
    {
        $this->onFlushCallback = $callback;
    }

    // ============================================================
    // JsonlReaderInterface Implementation
    // ============================================================

    /**
     * {@inheritDoc}
     */
    public function readAll(string $filePath, ?JsonlProcessingContext $context = null): array
    {
        $context ??= new JsonlProcessingContext;
        $context->setCurrentOperation(OperationType::READING);

        if (! $this->fileSystem->exists($filePath)) {
            return [];
        }

        $lines = [];

        $this->readLineByLine($filePath, function ($line) use (&$lines, $context, $filePath): void {
            $lines[] = $line;
            $context->addWrittenLines($filePath, 1);
        }, $context);

        $context->complete();

        return $lines;
    }

    /**
     * {@inheritDoc}
     */
    public function readLineByLine(string $filePath, callable $callback, ?JsonlProcessingContext $context = null): void
    {
        $context ??= new JsonlProcessingContext;

        if (! $this->fileSystem->exists($filePath)) {
            throw new JsonlException("File does not exist: {$filePath}");
        }

        $content = $this->fileSystem->get($filePath);
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            if ($trimmedLine === '') {
                continue;
            }

            $data = json_decode($trimmedLine, true);

            if ($data !== null) {
                $callback($data);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function search(string $filePath, callable $filter, ?JsonlProcessingContext $context = null): array
    {
        $context ??= new JsonlProcessingContext;
        $context->setCurrentOperation(OperationType::SEARCHING);

        $results = [];

        $this->readLineByLine($filePath, function ($line) use ($filter, &$results, $context, $filePath): void {
            if ($filter($line)) {
                $results[] = $line;
            }
            $context->addWrittenLines($filePath, 1);
        }, $context);

        $context->complete();

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function searchMultiple(array $filePaths, callable $filter, ?JsonlProcessingContext $context = null): array
    {
        $context ??= new JsonlProcessingContext;
        $context->setCurrentOperation(OperationType::SEARCHING_MULTIPLE);

        $results = [];

        foreach ($filePaths as $filePath) {
            if (! $this->fileSystem->exists($filePath)) {
                continue;
            }

            $fileResults = $this->search($filePath, $filter, $context);
            $results = array_merge($results, $fileResults);
            $context->addProcessedFile($filePath);
        }

        $context->complete();

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function getLastLine(string $filePath, ?JsonlProcessingContext $context = null): ?array
    {
        $context ??= new JsonlProcessingContext;

        if (! $this->fileSystem->exists($filePath)) {
            return null;
        }

        $content = $this->fileSystem->get($filePath);
        $lines = explode("\n", trim($content));
        $lines = array_filter($lines, fn ($line) => trim($line) !== '');

        if (empty($lines)) {
            return null;
        }

        $lastLine = end($lines);

        return json_decode($lastLine, true);
    }

    /**
     * {@inheritDoc}
     */
    public function getFirstLine(string $filePath, ?JsonlProcessingContext $context = null): ?array
    {
        $context ??= new JsonlProcessingContext;

        if (! $this->fileSystem->exists($filePath)) {
            return null;
        }

        $content = $this->fileSystem->get($filePath);
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            if ($trimmedLine !== '') {
                return json_decode($trimmedLine, true);
            }
        }

        return null;
    }

    // ============================================================
    // JsonlCleanerInterface Implementation
    // ============================================================

    /**
     * {@inheritDoc}
     */
    public function cleanOlderThan(int $days, string $basePath, ?JsonlProcessingContext $context = null): int
    {
        $context ??= new JsonlProcessingContext;
        $context->setCurrentOperation(OperationType::CLEANING_OLDER_THAN);

        $cutoffTime = time() - ($days * 86400);
        $deletedCount = 0;

        $pattern = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'**'.DIRECTORY_SEPARATOR.'*.jsonl';
        $files = $this->fileSystem->glob($pattern);

        foreach ($files as $file) {
            if ($this->fileSystem->lastModified($file) < $cutoffTime) {
                if ($this->fileSystem->delete($file)) {
                    $deletedCount++;
                    $context->addProcessedFile($file);
                }
            }
        }

        $context->complete();

        return $deletedCount;
    }

    /**
     * {@inheritDoc}
     */
    public function cleanExpired(string $basePath, callable $isExpired, ?JsonlProcessingContext $context = null): int
    {
        $context ??= new JsonlProcessingContext;
        $context->setCurrentOperation(OperationType::CLEANING_EXPIRED);

        if (! is_dir($basePath)) {
            $context->complete();

            return 0;
        }

        $deletedCount = 0;
        $files = $this->findAllJsonlFiles($basePath);

        foreach ($files as $filePath) {
            $this->executeWithLock($filePath, function () use ($filePath, $isExpired, &$deletedCount, $context): void {
                $lines = $this->readAll($filePath, $context);
                $validLines = [];

                foreach ($lines as $line) {
                    if (! $isExpired($line)) {
                        $validLines[] = $line;
                    } else {
                        $deletedCount++;
                    }
                }

                $this->applyCleanupToFile($filePath, $validLines, $context);
            });
        }

        $context->complete();

        return $deletedCount;
    }

    /**
     * {@inheritDoc}
     */
    public function cleanByPattern(string $pattern, ?JsonlProcessingContext $context = null): int
    {
        $context ??= new JsonlProcessingContext;
        $context->setCurrentOperation(OperationType::CLEANING_BY_PATTERN);

        $deletedCount = 0;
        $files = $this->fileSystem->glob($pattern);

        foreach ($files as $file) {
            if ($this->fileSystem->delete($file)) {
                $deletedCount++;
                $context->addProcessedFile($file);
            }
        }

        $context->complete();

        return $deletedCount;
    }

    /**
     * {@inheritDoc}
     */
    public function dryRun(string $basePath, callable $filter, ?JsonlProcessingContext $context = null): array
    {
        $context ??= new JsonlProcessingContext;
        $context->setCurrentOperation(OperationType::DRY_RUN);

        $filesToDelete = [];
        $pattern = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'**'.DIRECTORY_SEPARATOR.'*.jsonl';
        $files = $this->fileSystem->glob($pattern);

        foreach ($files as $file) {
            if ($filter($file)) {
                $filesToDelete[] = $file;
                $context->addProcessedFile($file);
            }
        }

        $context->complete();

        return $filesToDelete;
    }

    /**
     * {@inheritDoc}
     */
    public function clear(string $basePath, ?JsonlProcessingContext $context = null): int
    {
        $pattern = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'**'.DIRECTORY_SEPARATOR.'*.jsonl';

        return $this->cleanByPattern($pattern, $context);
    }

    // ============================================================
    // JsonlLockInterface Implementation
    // ============================================================

    /**
     * {@inheritDoc}
     */
    public function acquire(string $filePath, int $timeout = 5): bool
    {
        $lockKey = $this->getLockKey($filePath);

        if (isset($this->locks[$lockKey]) && $this->locks[$lockKey]->isAcquired()) {
            return true;
        }

        $startTime = microtime(true);
        $lockFile = $filePath.'.lock';

        while (true) {
            if (! $this->fileSystem->exists($lockFile)) {
                $this->fileSystem->put($lockFile, (string) getmypid());
                $this->locks[$lockKey] = new JsonlLockVO(null, $lockFile);

                return true;
            }

            if ((microtime(true) - $startTime) >= $timeout) {
                throw new JsonlLockException("Timeout acquiring lock for: {$filePath}");
            }

            usleep(50000);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function release(string $filePath): void
    {
        $lockKey = $this->getLockKey($filePath);

        if (isset($this->locks[$lockKey])) {
            $lock = $this->locks[$lockKey];
            $this->fileSystem->delete($lock->getLockFilePath());
            unset($this->locks[$lockKey]);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function executeWithLock(string $filePath, callable $callback): mixed
    {
        $this->acquire($filePath);

        try {
            return $callback();
        } finally {
            $this->release($filePath);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function isLocked(string $filePath): bool
    {
        $lockKey = $this->getLockKey($filePath);

        return isset($this->locks[$lockKey]) && $this->locks[$lockKey]->isAcquired();
    }

    // ============================================================
    // Public Additional Methods
    // ============================================================

    /**
     * {@inheritDoc}
     */
    public function getFilePath(AbstractRecord $entity): string
    {
        return $this->pathStrategy->getFilePath($entity);
    }

    /**
     * {@inheritDoc}
     */
    public function getFilesToScan(AbstractRecord $query): array
    {
        return $this->pathStrategy->getFilesToScan($query);
    }

    /**
     * Changes the path strategy at runtime.
     *
     * Useful for testing or switching behavior dynamically.
     */
    public function setPathStrategy(JsonlPathStrategyInterface $pathStrategy): void
    {
        $this->pathStrategy = $pathStrategy;
    }

    /**
     * {@inheritDoc}
     */
    public function isExpired(CacheJsonlRecord $record): bool
    {
        $metadata = new CacheJsonlMetadataVO($record);

        return $metadata->isExpired();
    }

    /**
     * Decodes a cached value back to a StrictDataObject.
     *
     * @param  string  $encodedValue  JSON encoded value
     * @param  string  $typeString  Original PHP type (unused, kept for API compatibility)
     */
    public function decodeCacheValue(string $encodedValue, string $typeString): StrictDataObject
    {
        $decoded = json_decode($encodedValue, true);

        return new StrictDataObject($decoded);
    }

    // ============================================================
    // Private Helper Methods
    // ============================================================

    /**
     * Transforms an entity into an array suitable for JSON encoding.
     *
     * @throws JsonlException If the entity type is not supported
     */
    private function prepareDataForWrite(AbstractRecord $entity): array
    {
        if ($entity instanceof CacheJsonlRecord) {
            return $this->prepareCacheData($entity);
        }

        if ($entity instanceof LogJsonlRecord) {
            return $this->prepareLogData($entity);
        }

        throw new JsonlException('Unsupported record type: '.$entity::class);
    }

    /**
     * Prepares cache record data for JSON encoding.
     */
    private function prepareCacheData(CacheJsonlRecord $record): array
    {
        $decoded = json_decode($record->value, true);
        $dataObject = new StrictDataObject($decoded);
        $cacheValue = new CacheValueVO($dataObject);

        return [
            'key' => $record->key,
            'value' => $cacheValue->getEncodedValue(),
            'expires_at' => $record->expires_at?->getValue(),
        ];
    }

    /**
     * Prepares log record data for JSON encoding.
     */
    private function prepareLogData(LogJsonlRecord $record): array
    {
        return [
            'time' => $record->time->getValue(),
            'level' => $record->level,
            'type' => $record->type,
            'payload' => $record->payload->toArray(),
        ];
    }

    /**
     * Encodes data to a JSON line with newline terminator.
     *
     * @param  array<string, mixed>  $data  Data to encode
     * @return string JSON line with trailing newline
     *
     * @throws JsonlException If JSON encoding fails
     */
    private function encodeToJsonLine(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new JsonlException('Failed to encode JSON: '.json_last_error_msg());
        }

        return $json."\n";
    }

    /**
     * Ensures the directory for a file path exists, creating it if necessary.
     */
    private function ensureDirectoryExists(string $filePath): void
    {
        $directory = dirname($filePath);

        if (! $this->fileSystem->isDirectory($directory)) {
            $this->fileSystem->makeDirectory($directory, $this->directoryPermission, true);
        }
    }

    /**
     * Flushes the buffer for a single file path.
     */
    private function flushSingleBuffer(string $filePath, JsonlProcessingContext $context): void
    {
        if (empty($this->buffer[$filePath])) {
            return;
        }

        $content = '';

        foreach ($this->buffer[$filePath] as $entity) {
            $data = $this->prepareDataForWrite($entity);
            $content .= $this->encodeToJsonLine($data);
        }

        $this->fileSystem->append($filePath, $content);
        $count = count($this->buffer[$filePath]);
        $context->addWrittenLines($filePath, $count);

        $this->buffer[$filePath] = [];

        if ($this->onFlushCallback !== null) {
            call_user_func($this->onFlushCallback, $filePath, $count);
        }
    }

    /**
     * Finds all JSONL files recursively within a base path.
     *
     * @return array<string>
     */
    private function findAllJsonlFiles(string $basePath): array
    {
        $directory = new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $regex = new \RegexIterator($iterator, '/\.jsonl$/i');

        $files = [];

        foreach ($regex as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }

    /**
     * Applies cleanup to a single file (delete or rewrite).
     *
     * @param  array<array<string, mixed>>  $validLines  Lines to keep
     */
    private function applyCleanupToFile(string $filePath, array $validLines, JsonlProcessingContext $context): void
    {
        if (empty($validLines)) {
            $this->fileSystem->delete($filePath);
            $context->addProcessedFile($filePath);

            return;
        }

        if (count($validLines) !== 0) {
            $this->rewriteFile($filePath, $validLines);
        }
    }

    /**
     * Rewrites a file with new content (used for removing expired lines).
     *
     * @param  array<array<string, mixed>>  $lines  Lines to write
     */
    private function rewriteFile(string $filePath, array $lines): void
    {
        $tempFile = $filePath.'.tmp';
        $content = '';

        foreach ($lines as $line) {
            $content .= $this->encodeToJsonLine($line);
        }

        $this->fileSystem->put($tempFile, $content);
        $this->fileSystem->move($tempFile, $filePath);
    }

    /**
     * Generates a unique key for file locking.
     */
    private function getLockKey(string $filePath): string
    {
        return $filePath;
    }
}
