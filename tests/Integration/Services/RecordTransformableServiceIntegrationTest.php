<?php

declare(strict_types=1);

namespace AndyDefer\PhpServices\Tests\Integration\Services;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\PhpServices\Services\RecordTransformableService;
use AndyDefer\PhpServices\Tests\Fixtures\Collections\TestPostRecordCollection;
use AndyDefer\PhpServices\Tests\Fixtures\Collections\TestUserRecordCollection;
use AndyDefer\PhpServices\Tests\Fixtures\Enums\TestUserRole;
use AndyDefer\PhpServices\Tests\Fixtures\Enums\TestUserStatus;
use AndyDefer\PhpServices\Tests\Fixtures\Models\TestUser;
use AndyDefer\PhpServices\Tests\Fixtures\Records\TestPostRecord;
use AndyDefer\PhpServices\Tests\Fixtures\Records\TestUserRecord;
use AndyDefer\PhpServices\Tests\IntegrationTestCase;

final class RecordTransformableServiceIntegrationTest extends IntegrationTestCase
{
    private RecordTransformableService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RecordTransformableService;
    }

    public function test_to_record_converts_database_model_correctly(): void
    {
        // Arrange
        $user = TestUser::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'status' => TestUserStatus::ACTIVE->value,
            'role' => TestUserRole::USER->value,
            'age' => 25,
            'metadata' => ['premium' => true, 'score' => 100],
        ]);

        // Act
        $result = $this->service->toRecord($user, TestUserRecord::class);

        // Assert
        $this->assertSame($user->id, $result->id);
        $this->assertSame('Jane Doe', $result->name);
        $this->assertSame('jane@example.com', $result->email);
        $this->assertSame(TestUserStatus::ACTIVE, $result->status);
        $this->assertSame(TestUserRole::USER, $result->role);
        $this->assertSame(25, $result->age);
        $this->assertInstanceOf(StrictDataObject::class, $result->metadata);
        $this->assertTrue($result->metadata->premium);
        $this->assertSame(100, $result->metadata->score);
    }

    public function test_to_record_with_relations_converts_relations_correctly(): void
    {
        // Arrange
        $user = TestUser::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'status' => TestUserStatus::ACTIVE->value,
            'role' => TestUserRole::USER->value,
            'age' => 25,
            'metadata' => ['premium' => true],
        ]);

        $user->posts()->createMany([
            ['title' => 'Post 1', 'body' => 'Content 1'],
            ['title' => 'Post 2', 'body' => 'Content 2'],
        ]);

        $user->load('posts');

        // Act
        $result = $this->service->toRecord($user, TestUserRecord::class);

        /** @var TestPostRecord $firstPost */
        $firstPost = $result->posts->first();

        // Assert
        $this->assertInstanceOf(TestPostRecordCollection::class, $result->posts);
        $this->assertCount(2, $result->posts);
        $this->assertInstanceOf(TestPostRecord::class, $firstPost);
        $this->assertSame(1, $firstPost->id);
    }

    public function test_to_record_collection_converts_multiple_models(): void
    {
        // Arrange
        TestUser::create([
            'name' => 'User 1',
            'email' => 'user1@example.com',
            'status' => TestUserStatus::ACTIVE->value,
            'role' => TestUserRole::USER->value,
            'metadata' => [],
        ]);

        TestUser::create([
            'name' => 'User 2',
            'email' => 'user2@example.com',
            'status' => TestUserStatus::INACTIVE->value,
            'role' => TestUserRole::GUEST->value,
            'metadata' => [],
        ]);

        $users = TestUser::all();

        // Act
        $result = $this->service->toRecordCollection($users, TestUserRecordCollection::class);

        /** @var TestUserRecord $firstUser */
        $firstUser = $result->first();

        /** @var TestUserRecord $lastUser */
        $lastUser = $result->last();

        // Assert
        $this->assertCount(2, $result);
        $this->assertInstanceOf(TestUserRecord::class, $firstUser);
        $this->assertSame('User 1', $firstUser->name);
        $this->assertSame('User 2', $lastUser->name);
    }

    public function test_to_record_collection_with_relations_converts_relations(): void
    {
        // Arrange
        $user1 = TestUser::create([
            'name' => 'User 1',
            'email' => 'user1@example.com',
            'status' => TestUserStatus::ACTIVE->value,
            'role' => TestUserRole::USER->value,
            'metadata' => [],
        ]);

        $user2 = TestUser::create([
            'name' => 'User 2',
            'email' => 'user2@example.com',
            'status' => TestUserStatus::ACTIVE->value,
            'role' => TestUserRole::USER->value,
            'metadata' => [],
        ]);

        $user1->posts()->createMany([
            ['title' => 'User 1 Post', 'body' => 'Content'],
        ]);

        $user2->posts()->createMany([
            ['title' => 'User 2 Post', 'body' => 'Content'],
        ]);

        $users = TestUser::with('posts')->get();

        // Act
        $result = $this->service->toRecordCollection($users, TestUserRecordCollection::class);

        /** @var TestUserRecord $firstUser */
        $firstUser = $result->first();

        /** @var TestUserRecord $lastUser */
        $lastUser = $result->last();

        /** @var TestPostRecord $firstUserFirstPost */
        $firstUserFirstPost = $firstUser->posts->first();

        /** @var TestPostRecord $lastUserFirstPost */
        $lastUserFirstPost = $lastUser->posts->first();

        // Assert
        $this->assertCount(2, $result);
        $this->assertInstanceOf(TestPostRecordCollection::class, $firstUser->posts);
        $this->assertCount(1, $firstUser->posts);
        $this->assertInstanceOf(TestPostRecord::class, $firstUserFirstPost);
        $this->assertSame('User 1 Post', $firstUserFirstPost->title);
        $this->assertSame('User 2 Post', $lastUserFirstPost->title);
    }

    public function test_to_record_handles_null_metadata(): void
    {
        // Arrange
        $user = TestUser::create([
            'name' => 'Null User',
            'email' => 'null@example.com',
            'status' => TestUserStatus::ACTIVE->value,
            'role' => TestUserRole::USER->value,
            'age' => null,
            'metadata' => null,
        ]);

        // Act
        $result = $this->service->toRecord($user, TestUserRecord::class);

        // Assert
        $this->assertSame($user->id, $result->id);
        $this->assertSame('Null User', $result->name);
        $this->assertNull($result->age);
        $this->assertNull($result->metadata);
    }
}
