<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl;

use AndyDefer\LaravelJsonl\Config\JsonlConfig;
use AndyDefer\LaravelJsonl\Config\JsonlConfigInterface;
use AndyDefer\LaravelJsonl\Contracts\JsonlCleanerInterface;
use AndyDefer\LaravelJsonl\Contracts\JsonlLockInterface;
use AndyDefer\LaravelJsonl\Contracts\JsonlPathStrategyInterface;
use AndyDefer\LaravelJsonl\Contracts\JsonlReaderInterface;
use AndyDefer\LaravelJsonl\Contracts\JsonlWriterInterface;
use AndyDefer\LaravelJsonl\Strategies\TemporalPathStrategy;
use AndyDefer\PhpServices\Contracts\FileSystemInterface;
use AndyDefer\PhpServices\Services\FileSystemService;
use Illuminate\Support\ServiceProvider;

class LaravelJsonlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Enregistrer la configuration
        $this->app->singleton(JsonlConfigInterface::class, JsonlConfig::class);

        // Enregistrer FileSystemService
        $this->app->singleton(FileSystemInterface::class, FileSystemService::class);

        // Enregistrer la stratégie de chemin par défaut
        $this->app->bind(JsonlPathStrategyInterface::class, function ($app) {
            $config = $app->make(JsonlConfigInterface::class);

            return new TemporalPathStrategy($config->basePath());
        });

        // Enregistrer le service JSONL
        $this->app->singleton(JsonlService::class, function ($app) {
            $config = $app->make(JsonlConfigInterface::class);

            return new JsonlService(
                pathStrategy: $app->make(JsonlPathStrategyInterface::class),
                fileSystem: $app->make(FileSystemInterface::class),
                defaultBufferSize: $config->bufferSize(),
                directoryPermission: $config->directoryPermission(),
            );
        });

        // Enregistrer les interfaces vers JsonlService
        $this->app->bind(JsonlWriterInterface::class, JsonlService::class);
        $this->app->bind(JsonlReaderInterface::class, JsonlService::class);
        $this->app->bind(JsonlCleanerInterface::class, JsonlService::class);
        $this->app->bind(JsonlLockInterface::class, JsonlService::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/jsonl.php' => config_path('jsonl.php'),
        ], 'jsonl-config');
    }
}
