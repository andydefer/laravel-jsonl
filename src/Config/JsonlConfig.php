<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Config;

use AndyDefer\PhpServices\Enums\PermissionMode;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Implémentation de la configuration JSONL pour Laravel
 * Utilise le fichier de config Laravel
 *
 * @author Andy Defer
 */
final class JsonlConfig implements JsonlConfigInterface
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function basePath(): string
    {
        return $this->config->get('jsonl.base_path', storage_path('jsonl'));
    }

    public function bufferSize(): ?int
    {
        $size = $this->config->get('jsonl.buffer_size');

        if ($size === null) {
            return null;
        }

        $intSize = (int) $size;

        return $intSize > 0 ? $intSize : null;
    }

    public function directoryPermission(): PermissionMode
    {
        $permission = $this->config->get('jsonl.directory_permission', 755);

        return match ($permission) {
            755 => PermissionMode::DIRECTORY,
            750 => PermissionMode::TEAM_DIRECTORY,
            700 => PermissionMode::PRIVATE_DIRECTORY,
            600 => PermissionMode::PRIVATE,
            644 => PermissionMode::PUBLIC_FILE,
            640 => PermissionMode::SHARED_CONFIG,
            777 => PermissionMode::WORLD_WRITABLE,
            default => PermissionMode::DIRECTORY,
        };
    }

    public function isBufferEnabled(): bool
    {
        return $this->bufferSize() !== null && $this->bufferSize() > 0;
    }
}
