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

class JsonlService implements JsonlCleanerInterface, JsonlLockInterface, JsonlReaderInterface, JsonlWriterInterface
{
    private array $locks = [];

    private array $buffer = [];

    private int $bufferSize = 0;

    private $onFlushCallback = null;

    public function __construct(
        private JsonlPathStrategyInterface $pathStrategy,
        private FileSystemInterface $fileSystem,
        private ?int $defaultBufferSize = null,
        private PermissionMode $directoryPermission = PermissionMode::DIRECTORY,
    ) {
        if ($defaultBufferSize !== null && $defaultBufferSize > 0) {
            $this->enableBuffer($defaultBufferSize);
        }
    }

    // ============================================================
    // JsonlWriterInterface
    // ============================================================

    public function write(AbstractRecord $entity, bool $lock = true, ?JsonlProcessingContext $context = null): void
    {
        $context ??= new JsonlProcessingContext;
        $context->setCurrentOperation(OperationType::WRITING);

        try {
            $filePath = $this->pathStrategy->getFilePath($entity);

            $data = $this->prepareDataForWrite($entity);

            $jsonLine = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

            if ($jsonLine === false) {
                throw new JsonlException('Failed to encode JSON: ' . json_last_error_msg());
            }

            $this->ensureDirectoryExists($filePath);

            $callback = function () use ($filePath, $jsonLine, $context) {
                $this->fileSystem->append($filePath, $jsonLine);
                $context->addWrittenLines($filePath, 1);
                $context->addProcessedFile($filePath);
            };

            if ($lock) {
                $this->executeWithLock($filePath, $callback);
            } else {
                $callback();
            }

            $context->complete();
        } catch (\Exception $e) {
            $context->setLastError($e->getMessage());
            throw new JsonlException($e->getMessage(), 0, $e);
        }
    }

    private function prepareDataForWrite(AbstractRecord $entity): array
    {
        if ($entity instanceof CacheJsonlRecord) {
            $decoded = json_decode($entity->value, true);
            $dataObject = new StrictDataObject($decoded);
            $CacheValueVO = new CacheValueVO($dataObject);

            return [
                'key' => $entity->key,
                'value' => $CacheValueVO->getEncodedValue(),
                'value_type' => $CacheValueVO->getType()->value,
                'expires_at' => $entity->expires_at?->getValue(),
            ];
        }

        if ($entity instanceof LogJsonlRecord) {
            return [
                'time' => $entity->time->getValue(),
                'level' => $entity->level,
                'type' => $entity->type,
                'payload' => $entity->payload->toArray(),
            ];
        }

        throw new JsonlException('Unsupported record type: ' . get_class($entity));
    }

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

            $callback = function () use ($filePath, $entities, $context) {
                $content = '';
                foreach ($entities as $entity) {
                    $data = $this->prepareDataForWrite($entity);
                    $content .= json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
                }
                $this->fileSystem->append($filePath, $content);
                $context->addWrittenLines($filePath, count($entities));
                $context->addProcessedFile($filePath);
            };

            if ($lock) {
                $this->executeWithLock($filePath, $callback);
            } else {
                $callback();
            }

            $context->complete();
        } catch (\Exception $e) {
            $context->setLastError($e->getMessage());
            throw new JsonlException($e->getMessage(), 0, $e);
        }
    }

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

    public function flushBuffer(?string $filePath = null, ?JsonlProcessingContext $context = null): void
    {
        $context ??= new JsonlProcessingContext;

        if ($filePath !== null) {
            if (! empty($this->buffer[$filePath])) {
                $content = '';
                foreach ($this->buffer[$filePath] as $entity) {
                    $data = $this->prepareDataForWrite($entity);
                    $content .= json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
                }
                $this->fileSystem->append($filePath, $content);
                $count = count($this->buffer[$filePath]);
                $context->addWrittenLines($filePath, $count);
                $this->buffer[$filePath] = [];

                if ($this->onFlushCallback !== null) {
                    call_user_func($this->onFlushCallback, $filePath, $count);
                }
            }

            return;
        }

        foreach (array_keys($this->buffer) as $path) {
            $this->flushBuffer($path, $context);
        }
    }

    public function enableBuffer(int $size = 100): void
    {
        $this->bufferSize = $size;
    }

    public function disableBuffer(): void
    {
        $this->flushBuffer();
        $this->bufferSize = 0;
    }

    public function onFlush(callable $callback): void
    {
        $this->onFlushCallback = $callback;
    }

    // ============================================================
    // JsonlReaderInterface
    // ============================================================

    public function readAll(string $filePath, ?JsonlProcessingContext $context = null): array
    {
        $context ??= new JsonlProcessingContext;
        $context->setCurrentOperation(OperationType::READING);

        if (! $this->fileSystem->exists($filePath)) {
            return [];
        }

        $lines = [];

        $this->readLineByLine($filePath, function ($line) use (&$lines, $context, $filePath) {
            $lines[] = $line;
            $context->addWrittenLines($filePath, 1);
        }, $context);

        $context->complete();

        return $lines;
    }

    public function readLineByLine(string $filePath, callable $callback, ?JsonlProcessingContext $context = null): void
    {
        $context ??= new JsonlProcessingContext;

        if (! $this->fileSystem->exists($filePath)) {
            throw new JsonlException("File does not exist: {$filePath}");
        }

        $content = $this->fileSystem->get($filePath);
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $data = json_decode($line, true);
            if ($data !== null) {
                $callback($data);
            }
        }
    }

    public function search(string $filePath, callable $filter, ?JsonlProcessingContext $context = null): array
    {
        $context ??= new JsonlProcessingContext;
        $context->setCurrentOperation(OperationType::SEARCHING);

        $results = [];

        $this->readLineByLine($filePath, function ($line) use ($filter, &$results, $context, $filePath) {
            if ($filter($line)) {
                $results[] = $line;
            }
            $context->addWrittenLines($filePath, 1);
        }, $context);

        $context->complete();

        return $results;
    }

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

    public function getLastLine(string $filePath, ?JsonlProcessingContext $context = null): ?array
    {
        $context ??= new JsonlProcessingContext;

        if (! $this->fileSystem->exists($filePath)) {
            return null;
        }

        $content = $this->fileSystem->get($filePath);
        $lines = explode("\n", trim($content));

        $lines = array_filter($lines, fn($line) => trim($line) !== '');

        if (empty($lines)) {
            return null;
        }

        $lastLine = end($lines);

        return json_decode($lastLine, true);
    }

    public function getFirstLine(string $filePath, ?JsonlProcessingContext $context = null): ?array
    {
        $context ??= new JsonlProcessingContext;

        if (! $this->fileSystem->exists($filePath)) {
            return null;
        }

        $content = $this->fileSystem->get($filePath);
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            if (trim($line) !== '') {
                return json_decode($line, true);
            }
        }

        return null;
    }

    // ============================================================
    // JsonlCleanerInterface
    // ============================================================

    public function cleanOlderThan(int $days, string $basePath, ?JsonlProcessingContext $context = null): int
    {
        $context ??= new JsonlProcessingContext;
        $context->setCurrentOperation(OperationType::CLEANING_OLDER_THAN);

        $cutoffTime = time() - ($days * 86400);
        $deletedCount = 0;

        $pattern = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '**' . DIRECTORY_SEPARATOR . '*.jsonl';
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

    public function cleanExpired(string $basePath, callable $isExpired, ?JsonlProcessingContext $context = null): int
    {
        $context ??= new JsonlProcessingContext;
        $context->setCurrentOperation(OperationType::CLEANING_EXPIRED);

        $deletedCount = 0;
        $pattern = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '**' . DIRECTORY_SEPARATOR . '*.jsonl';
        $files = $this->fileSystem->glob($pattern);

        foreach ($files as $file) {
            $this->executeWithLock($file, function () use ($file, $isExpired, &$deletedCount, $context) {
                $lines = $this->readAll($file, $context);
                $validLines = [];

                foreach ($lines as $line) {
                    if (! $isExpired($line)) {
                        $validLines[] = $line;
                    } else {
                        $deletedCount++;
                    }
                }

                if (count($validLines) !== count($lines)) {
                    if (empty($validLines)) {
                        $this->fileSystem->delete($file);
                        $context->addProcessedFile($file);
                    } else {
                        $this->rewriteFile($file, $validLines);
                    }
                }
            });
        }

        $context->complete();

        return $deletedCount;
    }

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

    public function dryRun(string $basePath, callable $filter, ?JsonlProcessingContext $context = null): array
    {
        $context ??= new JsonlProcessingContext;
        $context->setCurrentOperation(OperationType::DRY_RUN);

        $filesToDelete = [];
        $pattern = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '**' . DIRECTORY_SEPARATOR . '*.jsonl';
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

    public function clear(string $basePath, ?JsonlProcessingContext $context = null): int
    {
        $pattern = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '**' . DIRECTORY_SEPARATOR . '*.jsonl';

        return $this->cleanByPattern($pattern, $context);
    }

    // ============================================================
    // JsonlLockInterface
    // ============================================================

    public function acquire(string $filePath, int $timeout = 5): bool
    {
        $lockKey = $this->getLockKey($filePath);

        if (isset($this->locks[$lockKey]) && $this->locks[$lockKey]->isAcquired()) {
            return true;
        }

        $startTime = microtime(true);
        $lockFile = $filePath . '.lock';

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

    public function release(string $filePath): void
    {
        $lockKey = $this->getLockKey($filePath);

        if (isset($this->locks[$lockKey])) {
            $lock = $this->locks[$lockKey];
            $this->fileSystem->delete($lock->getLockFilePath());
            unset($this->locks[$lockKey]);
        }
    }

    public function executeWithLock(string $filePath, callable $callback): mixed
    {
        $this->acquire($filePath);

        try {
            return $callback();
        } finally {
            $this->release($filePath);
        }
    }

    public function isLocked(string $filePath): bool
    {
        $lockKey = $this->getLockKey($filePath);

        return isset($this->locks[$lockKey]) && $this->locks[$lockKey]->isAcquired();
    }

    // ============================================================
    // Méthodes publiques supplémentaires
    // ============================================================

    public function getFilePath(AbstractRecord $entity): string
    {
        return $this->pathStrategy->getFilePath($entity);
    }

    public function getFilesToScan(AbstractRecord $query): array
    {
        return $this->pathStrategy->getFilesToScan($query);
    }

    public function setPathStrategy(JsonlPathStrategyInterface $pathStrategy): void
    {
        $this->pathStrategy = $pathStrategy;
    }

    public function isExpired(CacheJsonlRecord $record): bool
    {
        $metadata = new CacheJsonlMetadataVO($record);

        return $metadata->isExpired();
    }

    public function decodeCacheValue(string $encodedValue, string $typeString): StrictDataObject
    {
        $decoded = json_decode($encodedValue, true);

        return new StrictDataObject($decoded);
    }

    // ============================================================
    // Méthodes privées
    // ============================================================

    private function ensureDirectoryExists(string $filePath): void
    {
        $directory = dirname($filePath);

        if (! $this->fileSystem->isDirectory($directory)) {
            $this->fileSystem->makeDirectory($directory, $this->directoryPermission, true);
        }
    }

    private function rewriteFile(string $filePath, array $lines): void
    {
        $tempFile = $filePath . '.tmp';

        $content = '';
        foreach ($lines as $line) {
            $content .= json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        }

        $this->fileSystem->put($tempFile, $content);
        $this->fileSystem->move($tempFile, $filePath);
    }

    private function getLockKey(string $filePath): string
    {
        return $filePath;
    }
}
