<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl;

use AndyDefer\LaravelJsonl\Config\JsonlConfigInterface;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\Strategies\TemporalPathStrategy;
use AndyDefer\PhpServices\Services\FileSystemService;
use Illuminate\Support\ServiceProvider;

final class LaravelJsonlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Context (stateful - singleton)
        $this->app->singleton(JsonlContext::class);

        // Services (stateless)
        $this->app->singleton(FileSystemService::class);

        $this->app->singleton(TemporalPathStrategy::class, function ($app) {
            $config = $app->make(JsonlConfigInterface::class);

            return new TemporalPathStrategy($config->basePath());
        });

        $this->app->singleton(JsonlService::class, function ($app) {
            $config = $app->make(JsonlConfigInterface::class);

            return new JsonlService(
                pathStrategy: $app->make(TemporalPathStrategy::class),
                fileSystem: $app->make(FileSystemService::class),
                context: $app->make(JsonlContext::class),
                defaultBufferSize: $config->bufferSize(),
                directoryPermission: $config->directoryPermission(),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/jsonl.php' => config_path('jsonl.php'),
        ], 'jsonl-config');
    }
}
