<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::factory()->create([
            'slug' => 'admin',
            'permissions' => ['pages.view', 'pages.create', 'pages.edit', 'pages.delete'],
            'backend_access' => true,
            'is_active' => true,
        ]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->roles()->attach($role);
    }

    public function test_admin_can_create_page()
    {
        $response = $this->actingAs($this->admin)->post('/admin/pages', [
            'title' => 'Test Page',
            'slug' => 'test-page',
            'content' => '<p>Content</p>',
            'status' => 'published',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/admin/pages');
        $this->assertTrue(Page::where('title', 'Test Page')->exists());
    }

    public function test_admin_can_update_page()
    {
        $page = Page::factory()->create(['title' => 'Old Title']);

        $response = $this->actingAs($this->admin)->put('/admin/pages/'.$page->id, [
            'title' => 'New Title',
            'slug' => 'new-title',
            'content' => '<p>Updated</p>',
            'status' => 'draft',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/admin/pages');
        if (Page::where('title', 'Old Title')->exists()) {
            dump(Page::all()->toArray());
        }
        $this->assertTrue(Page::where('title', 'New Title')->exists());
        $this->assertFalse(Page::where('title', 'Old Title')->exists());
    }

    public function test_admin_can_create_page_with_empty_content()
    {
        $response = $this->actingAs($this->admin)->post('/admin/pages', [
            'title' => 'Empty Page',
            'slug' => 'empty-page',
            'content' => '',
            'status' => 'draft',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/admin/pages');
        $this->assertSame('', Page::where('slug', 'empty-page')->first()->content);
    }

    public function test_update_with_empty_content_clears_content()
    {
        $page = Page::factory()->create(['content' => '<p>Old</p>']);

        $response = $this->actingAs($this->admin)->put('/admin/pages/'.$page->id, [
            'title' => $page->title,
            'slug' => $page->slug,
            'content' => '',
            'status' => 'draft',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/admin/pages');
        $this->assertSame('', $page->fresh()->content);
    }

    public function test_update_without_content_keeps_existing_content()
    {
        $page = Page::factory()->create(['content' => '<p>Keep me</p>']);

        $response = $this->actingAs($this->admin)->put('/admin/pages/'.$page->id, [
            'title' => $page->title,
            'slug' => $page->slug,
            'status' => 'draft',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('<p>Keep me</p>', $page->fresh()->content);
    }
}
