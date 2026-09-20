<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AiChatTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::factory()->create([
            'slug' => 'admin',
            'permissions' => Role::availablePermissions(),
            'backend_access' => true,
            'is_active' => true,
        ]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->roles()->attach($role);
    }

    public function test_chat_returns_mocked_assistant_message(): void
    {
        $mock = Mockery::mock(AiService::class);
        $mock->shouldReceive('generateChatResponse')
            ->once()
            ->andReturn('Hello from mock assistant');
        $this->app->instance(AiService::class, $mock);

        $response = $this->actingAs($this->admin)->postJson(route('admin.ai.chat'), [
            'messages' => [
                ['role' => 'user', 'content' => 'Hi'],
            ],
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'Hello from mock assistant']);
    }

    public function test_chat_requires_authentication(): void
    {
        $this->postJson(route('admin.ai.chat'), [
            'messages' => [
                ['role' => 'user', 'content' => 'Hi'],
            ],
        ])->assertUnauthorized();
    }

    public function test_chat_validates_messages(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('admin.ai.chat'), [
                'messages' => 'not-an-array',
            ])
            ->assertStatus(422);
    }
}
