<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BannerControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::factory()->create([
            'slug' => 'admin',
            'permissions' => ['banners.view', 'banners.create', 'banners.edit', 'banners.delete'],
            'backend_access' => true,
            'is_active' => true,
        ]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->roles()->attach($adminRole);

        $viewerRole = Role::factory()->create([
            'slug' => 'viewer',
            'permissions' => ['banners.view'],
            'backend_access' => true,
            'is_active' => true,
        ]);
        $this->viewer = User::factory()->create(['is_active' => true]);
        $this->viewer->roles()->attach($viewerRole);
    }

    public function test_admin_can_list_banners(): void
    {
        Banner::create(['title' => 'Hero', 'is_active' => true, 'slides' => []]);

        $this->actingAs($this->admin)
            ->get(route('admin.banners.index'))
            ->assertOk();
    }

    public function test_admin_can_create_banner(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.banners.store'), [
            'title' => 'Homepage Hero',
            'slug' => 'homepage-hero',
            'is_active' => true,
            'slides' => [],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('admin.banners.index'));
        $this->assertTrue(Banner::where('title', 'Homepage Hero')->exists());
    }

    public function test_viewer_cannot_create_banner(): void
    {
        $this->actingAs($this->viewer)
            ->post(route('admin.banners.store'), [
                'title' => 'Nope',
                'is_active' => true,
            ])
            ->assertForbidden();
    }

    public function test_admin_can_delete_banner(): void
    {
        $banner = Banner::create(['title' => 'Temp', 'is_active' => true, 'slides' => []]);

        $this->actingAs($this->admin)
            ->delete(route('admin.banners.destroy', $banner->id))
            ->assertRedirect();

        $this->assertFalse(Banner::where('title', 'Temp')->exists());
    }
}
