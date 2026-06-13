<?php

declare(strict_types=1);

namespace AndyDefer\PhpServices\Tests\Integration\Services;

use AndyDefer\DomainStructures\Utils\DataObject;
use AndyDefer\PhpServices\Services\ModelTransformableService;
use AndyDefer\PhpServices\Tests\Fixtures\Collections\TestPostDataCollection;
use AndyDefer\PhpServices\Tests\Fixtures\Collections\TestUserDataCollection;
use AndyDefer\PhpServices\Tests\Fixtures\Data\TestPostData;
use AndyDefer\PhpServices\Tests\Fixtures\Data\TestUserData;
use AndyDefer\PhpServices\Tests\Fixtures\Enums\TestUserRole;
use AndyDefer\PhpServices\Tests\Fixtures\Enums\TestUserStatus;
use AndyDefer\PhpServices\Tests\Fixtures\Models\TestUser;
use AndyDefer\PhpServices\Tests\IntegrationTestCase;

final class ModelTransformableServiceIntegrationTest extends IntegrationTestCase
{
    private ModelTransformableService $service;

    protected function setUp(): void
    {

        parent::setUp();
        $this->service = new ModelTransformableService;
    }

    public function test_to_data_converts_database_model_correctly(): void
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
        $result = $this->service->toData($user, TestUserData::class);

        // Assert
        $this->assertSame($user->id, $result->id);
        $this->assertSame('Jane Doe', $result->name);
        $this->assertSame('jane@example.com', $result->email);
        $this->assertSame(TestUserStatus::ACTIVE, $result->status);
        $this->assertSame(TestUserRole::USER, $result->role);
        $this->assertSame(25, $result->age);
        $this->assertInstanceOf(DataObject::class, $result->metadata);
        $this->assertTrue($result->metadata->premium);
        $this->assertSame(100, $result->metadata->score);
    }

    public function test_to_data_with_relations_converts_relations_correctly(): void
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
        $result = $this->service->toData($user, TestUserData::class);

        /** @var TestPostData $firstPost */
        $firstPost = $result->posts->first();

        /** @var TestPostData $lastPost */
        $lastPost = $result->posts->last();

        // Assert
        $this->assertInstanceOf(TestPostDataCollection::class, $result->posts);
        $this->assertCount(2, $result->posts);
        $this->assertInstanceOf(TestPostData::class, $firstPost);
        $this->assertSame('Post 1', $firstPost->title);
        $this->assertSame('Content 2', $lastPost->body);
    }

    public function test_to_data_collection_converts_multiple_models(): void
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
        $result = $this->service->toDataCollection($users, TestUserDataCollection::class);

        /** @var TestUserData $firstUser */
        $firstUser = $result->first();

        /** @var TestUserData $lastUser */
        $lastUser = $result->last();

        // Assert
        $this->assertCount(2, $result);
        $this->assertInstanceOf(TestUserData::class, $firstUser);
        $this->assertSame('User 1', $firstUser->name);
        $this->assertSame('User 2', $lastUser->name);
    }

    public function test_to_data_collection_with_relations_converts_relations(): void
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
        $result = $this->service->toDataCollection($users, TestUserDataCollection::class);

        /** @var TestUserData $firstUser */
        $firstUser = $result->first();

        /** @var TestUserData $lastUser */
        $lastUser = $result->last();

        /** @var TestPostData $firstUserFirstPost */
        $firstUserFirstPost = $firstUser->posts->first();

        /** @var TestPostData $lastUserFirstPost */
        $lastUserFirstPost = $lastUser->posts->first();

        // Assert
        $this->assertCount(2, $result);
        $this->assertInstanceOf(TestPostDataCollection::class, $firstUser->posts);
        $this->assertCount(1, $firstUser->posts);
        $this->assertInstanceOf(TestPostData::class, $firstUserFirstPost);
        $this->assertSame('User 1 Post', $firstUserFirstPost->title);
        $this->assertSame('User 2 Post', $lastUserFirstPost->title);
    }

    public function test_to_data_handles_null_metadata(): void
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
        $result = $this->service->toData($user, TestUserData::class);

        // Assert
        $this->assertSame($user->id, $result->id);
        $this->assertSame('Null User', $result->name);
        $this->assertNull($result->age);
        $this->assertNull($result->metadata);
    }
}
