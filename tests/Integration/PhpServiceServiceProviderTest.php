<?php

declare(strict_types=1);

namespace AndyDefer\PhpServices\Tests\Integration;

use AndyDefer\PhpServices\Contracts\ModelTransformableInterface;
use AndyDefer\PhpServices\Contracts\PrimitiveTypeConverterInterface;
use AndyDefer\PhpServices\Contracts\RecordTransformableInterface;
use AndyDefer\PhpServices\Services\ModelTransformableService;
use AndyDefer\PhpServices\Services\PrimitiveTypeConverterService;
use AndyDefer\PhpServices\Services\RecordTransformableService;
use AndyDefer\PhpServices\Tests\IntegrationTestCase;

final class PhpServiceServiceProviderTest extends IntegrationTestCase
{
    public function test_service_provider_registers_model_transformable_interface(): void
    {
        // Act
        $instance = app(ModelTransformableInterface::class);

        // Assert
        $this->assertInstanceOf(ModelTransformableService::class, $instance);
    }

    public function test_service_provider_registers_model_transformable_as_singleton(): void
    {
        // Act
        $instance1 = app(ModelTransformableInterface::class);
        $instance2 = app(ModelTransformableInterface::class);

        // Assert
        $this->assertSame($instance1, $instance2);
    }

    public function test_service_provider_registers_record_transformable_interface(): void
    {
        // Act
        $instance = app(RecordTransformableInterface::class);

        // Assert
        $this->assertInstanceOf(RecordTransformableService::class, $instance);
    }

    public function test_service_provider_registers_record_transformable_as_singleton(): void
    {
        // Act
        $instance1 = app(RecordTransformableInterface::class);
        $instance2 = app(RecordTransformableInterface::class);

        // Assert
        $this->assertSame($instance1, $instance2);
    }

    public function test_service_provider_registers_primitive_type_converter_interface(): void
    {
        // Act
        $instance = app(PrimitiveTypeConverterInterface::class);

        // Assert
        $this->assertInstanceOf(PrimitiveTypeConverterService::class, $instance);
    }

    public function test_service_provider_registers_primitive_type_converter_as_singleton(): void
    {
        // Act
        $instance1 = app(PrimitiveTypeConverterInterface::class);
        $instance2 = app(PrimitiveTypeConverterInterface::class);

        // Assert
        $this->assertSame($instance1, $instance2);
    }

    public function test_service_provider_registers_all_services_independently(): void
    {
        // Act
        $modelTransformable = app(ModelTransformableInterface::class);
        $recordTransformable = app(RecordTransformableInterface::class);
        $primitiveConverter = app(PrimitiveTypeConverterInterface::class);

        // Assert
        $this->assertInstanceOf(ModelTransformableService::class, $modelTransformable);
        $this->assertInstanceOf(RecordTransformableService::class, $recordTransformable);
        $this->assertInstanceOf(PrimitiveTypeConverterService::class, $primitiveConverter);
        $this->assertNotSame($modelTransformable, $recordTransformable);
        $this->assertNotSame($modelTransformable, $primitiveConverter);
        $this->assertNotSame($recordTransformable, $primitiveConverter);
    }

    public function test_service_provider_returns_same_instance_for_repeated_resolution(): void
    {
        // Act
        $modelTransformable1 = app(ModelTransformableInterface::class);
        $modelTransformable2 = app(ModelTransformableInterface::class);
        $recordTransformable1 = app(RecordTransformableInterface::class);
        $recordTransformable2 = app(RecordTransformableInterface::class);
        $primitiveConverter1 = app(PrimitiveTypeConverterInterface::class);
        $primitiveConverter2 = app(PrimitiveTypeConverterInterface::class);

        // Assert
        $this->assertSame($modelTransformable1, $modelTransformable2);
        $this->assertSame($recordTransformable1, $recordTransformable2);
        $this->assertSame($primitiveConverter1, $primitiveConverter2);
    }

    public function test_service_provider_can_resolve_all_interfaces_simultaneously(): void
    {
        // Act & Assert - No exceptions should be thrown
        $resolved = [
            app(ModelTransformableInterface::class),
            app(RecordTransformableInterface::class),
            app(PrimitiveTypeConverterInterface::class),
        ];

        $this->assertCount(3, $resolved);

        // Vérification individuelle pour éviter l'erreur de type
        $this->assertInstanceOf(ModelTransformableService::class, $resolved[0]);
        $this->assertInstanceOf(RecordTransformableService::class, $resolved[1]);
        $this->assertInstanceOf(PrimitiveTypeConverterService::class, $resolved[2]);
    }
}
