<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Contexts;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\LaravelJsonl\ValueObjects\JsonlLockVO;

/**
 * Unified context for JSONL service state management.
 *
 * Holds both lock and buffer state for the JSONL service.
 *
 * @author Andy Defer
 */
final class JsonlContext
{
    // Lock state
    /** @var array<string, JsonlLockVO> */
    private array $locks = [];

    // Buffer state
    /** @var array<string, array<AbstractRecord>> */
    private array $buffer = [];

    private int $bufferSize = 0;

    /** @var callable(string, int):void|null */
    private $onFlushCallback = null;

    // ============================================================
    // Lock Management
    // ============================================================

    /**
     * Add a lock to the context.
     */
    public function addLock(string $key, JsonlLockVO $lock): void
    {
        $this->locks[$key] = $lock;
    }

    /**
     * Remove a lock from the context.
     */
    public function removeLock(string $key): void
    {
        unset($this->locks[$key]);
    }

    /**
     * Check if a lock exists in the context.
     */
    public function hasLock(string $key): bool
    {
        return isset($this->locks[$key]) && $this->locks[$key]->isAcquired();
    }

    /**
     * Get a lock from the context.
     */
    public function getLock(string $key): ?JsonlLockVO
    {
        return $this->locks[$key] ?? null;
    }

    /**
     * Get all active locks.
     *
     * @return array<string, JsonlLockVO>
     */
    public function getAllLocks(): array
    {
        return $this->locks;
    }

    /**
     * Clear all locks.
     */
    public function clearLocks(): void
    {
        $this->locks = [];
    }

    // ============================================================
    // Buffer Management
    // ============================================================

    /**
     * Get the entire buffer.
     *
     * @return array<string, array<AbstractRecord>>
     */
    public function getBuffer(): array
    {
        return $this->buffer;
    }

    /**
     * Add an entity to the buffer for a specific file path.
     */
    public function addToBuffer(string $filePath, AbstractRecord $entity): void
    {
        if (! isset($this->buffer[$filePath])) {
            $this->buffer[$filePath] = [];
        }

        $this->buffer[$filePath][] = $entity;
    }

    /**
     * Get buffered entities for a specific file path.
     *
     * @return array<AbstractRecord>
     */
    public function getFileBuffer(string $filePath): array
    {
        return $this->buffer[$filePath] ?? [];
    }

    /**
     * Clear buffer for a specific file path.
     */
    public function clearFileBuffer(string $filePath): void
    {
        unset($this->buffer[$filePath]);
    }

    /**
     * Clear all buffers.
     */
    public function clearAllBuffers(): void
    {
        $this->buffer = [];
    }

    /**
     * Set the buffer size limit.
     */
    public function setBufferSize(int $size): void
    {
        $this->bufferSize = $size;
    }

    /**
     * Get the current buffer size limit.
     */
    public function getBufferSize(): int
    {
        return $this->bufferSize;
    }

    /**
     * Check if buffering is enabled.
     */
    public function isBufferEnabled(): bool
    {
        return $this->bufferSize > 0;
    }

    /**
     * Set the on-flush callback.
     *
     * @param  callable(string, int):void|null  $callback
     */
    public function setOnFlushCallback(?callable $callback): void
    {
        $this->onFlushCallback = $callback;
    }

    /**
     * Get the on-flush callback.
     *
     * @return callable(string, int):void|null
     */
    public function getOnFlushCallback(): ?callable
    {
        return $this->onFlushCallback;
    }

    /**
     * Execute the on-flush callback if defined.
     */
    public function triggerOnFlush(string $filePath, int $count): void
    {
        if ($this->onFlushCallback !== null) {
            call_user_func($this->onFlushCallback, $filePath, $count);
        }
    }
}
