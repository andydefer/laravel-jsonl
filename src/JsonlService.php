<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\DomainStructures\Normalizers\NormalizerChain;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
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
use AndyDefer\LaravelJsonl\ValueObjects\CacheJsonlMetadataVO;
use AndyDefer\LaravelJsonl\ValueObjects\JsonlLockVO;
use AndyDefer\PhpServices\Contracts\FileSystemInterface;
use AndyDefer\PhpServices\Enums\PermissionMode;
use Throwable;

/**
 * Main service for JSONL (JSON Lines) storage operations.
 *
 * This service is stateless. All state (locks, buffer) is managed
 * through the injected JsonlContext.
 *
 * Locking limitations
 * -------------------
 * The lock mechanism uses PID-based liveness checks via `posix_kill`.
 * It is reliable on a single host with a shared filesystem (local disk
 * or a POSIX-compliant shared volume). It is NOT reliable in the
 * following environments:
 *
 *  - Containers with PID namespaces: the PID of a process in container A
 *    does not exist in container B, so a legitimate lock held by A would
 *    be treated as orphan by B.
 *  - NFS / CIFS shared volumes between hosts: same issue, plus `flock`
 *    itself is unreliable on these filesystems.
 *  - PID reuse: a stale lock whose PID has been recycled by a third
 *    process will be treated as alive until the timeout, delaying writes.
 *
 * For these environments, disable locking (`write(..., lock: false)`) and
 * rely on an external coordination mechanism (Redis, database lock,
 * dedicated queue) or use a path strategy that isolates each writer to
 * its own file.
 *
 * @author Andy Defer
 */
class JsonlService implements JsonlCleanerInterface, JsonlLockInterface, JsonlReaderInterface, JsonlWriterInterface
{
    public function __construct(
        private JsonlPathStrategyInterface $pathStrategy,
        private readonly FileSystemInterface $fileSystem,
        private readonly JsonlContext $context,
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
        if (! $this->context->isBufferEnabled()) {
            $this->write($entity, true, $context);

            return;
        }

        $filePath = $this->pathStrategy->getFilePath($entity);
        $this->context->addToBuffer($filePath, $entity);

        $buffer = $this->context->getFileBuffer($filePath);

        if (count($buffer) >= $this->context->getBufferSize()) {
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

        foreach (array_keys($this->context->getBuffer()) as $path) {
            $this->flushSingleBuffer($path, $context);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function enableBuffer(int $size = 100): void
    {
        $this->context->setBufferSize($size);
    }

    /**
     * {@inheritDoc}
     */
    public function disableBuffer(): void
    {
        $this->flushBuffer();
        $this->context->setBufferSize(0);
    }

    /**
     * {@inheritDoc}
     */
    public function onFlush(callable $callback): void
    {
        $this->context->setOnFlushCallback($callback);
    }

    /**
     * Check if buffering is currently enabled.
     */
    public function isBufferEnabled(): bool
    {
        return $this->context->isBufferEnabled();
    }

    /**
     * Get the current buffer size.
     */
    public function getBufferSize(): int
    {
        return $this->context->getBufferSize();
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

        if ($this->context->hasLock($lockKey)) {
            return true;
        }

        $startTime = microtime(true);
        $lockFile = $filePath.'.lock';

        while (true) {
            if ((microtime(true) - $startTime) >= $timeout) {
                throw new JsonlLockException("Timeout acquiring lock for: {$filePath}");
            }

            if (! $this->fileSystem->exists($lockFile)) {
                $this->fileSystem->put($lockFile, (string) getmypid());
                $this->context->addLock($lockKey, new JsonlLockVO(null, $lockFile));

                return true;
            }

            $ownerPid = $this->readLockOwnerPid($lockFile);

            if ($ownerPid <= 0 || ! $this->isProcessAlive($ownerPid)) {
                $this->deleteLockFileSafely($lockFile);
                usleep(50000);

                continue;
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
        $lock = $this->context->getLock($lockKey);

        if ($lock !== null) {
            $this->fileSystem->delete($lock->getLockFilePath());
            $this->context->removeLock($lockKey);
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

        return $this->context->hasLock($lockKey);
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
     * Returns the base directory from the path strategy.
     */
    public function getBaseDirectory(): string
    {
        return $this->pathStrategy->getBaseDirectory();
    }

    /**
     * Check if a file exists.
     */
    public function fileExists(string $filePath): bool
    {
        return $this->fileSystem->exists($filePath);
    }

    /**
     * Changes the path strategy at runtime.
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
     * Reads the PID from an existing lock file, tolerating a missing or
     * empty file (concurrent deletion / partial write).
     *
     * Returns 0 when the PID cannot be read, so the caller treats the
     * lock as orphan.
     */
    private function readLockOwnerPid(string $lockFile): int
    {
        try {
            if (! $this->fileSystem->exists($lockFile)) {
                return 0;
            }

            $content = trim($this->fileSystem->get($lockFile));

            return $content === '' ? 0 : (int) $content;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Deletes the lock file, tolerating a concurrent deletion.
     *
     * The TOCTOU window between exists() and delete() is accepted because
     * delete() is idempotent and only called when we already consider the
     * lock orphan.
     */
    private function deleteLockFileSafely(string $lockFile): void
    {
        try {
            if ($this->fileSystem->exists($lockFile)) {
                $this->fileSystem->delete($lockFile);
            }
        } catch (Throwable) {
            // Ignore — the next iteration will re-check the timeout.
        }
    }

    /**
     * Checks whether a process is still alive.
     *
     * Uses `posix_kill($pid, 0)` which sends no signal and returns true
     * when the process exists. When the call fails with EPERM, the
     * process exists but is owned by another user: we must NOT treat it
     * as dead, otherwise we would delete a legitimate lock held by
     * another user.
     *
     * When the posix extension is unavailable, we conservatively assume
     * the process is alive so we never delete a legitimate lock.
     */
    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (! function_exists('posix_kill')) {
            return true;
        }

        if (posix_kill($pid, 0)) {
            return true;
        }

        // EPERM (errno 1) means the process exists but is owned by
        // another user: consider it alive.
        return posix_get_last_error() === 1;
    }

    /**
     * Transforms an entity into an array suitable for JSON encoding.
     *
     * @throws JsonlException If the entity type is not supported
     */
    private function prepareDataForWrite(AbstractRecord $entity): array
    {
        return NormalizerChain::get()->normalize($entity);
    }

    /**
     * Encodes data to a JSON line with newline terminator.
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
        $buffer = $this->context->getFileBuffer($filePath);

        if (empty($buffer)) {
            return;
        }

        $content = '';

        foreach ($buffer as $entity) {
            $data = $this->prepareDataForWrite($entity);
            $content .= $this->encodeToJsonLine($data);
        }

        $this->fileSystem->append($filePath, $content);
        $count = count($buffer);
        $context->addWrittenLines($filePath, $count);

        $this->context->clearFileBuffer($filePath);
        $this->context->triggerOnFlush($filePath, $count);
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
