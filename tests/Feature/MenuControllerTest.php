<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::factory()->create([
            'slug' => 'admin',
            'permissions' => ['menus.view', 'menus.create', 'menus.edit', 'menus.delete'],
            'backend_access' => true,
            'is_active' => true,
        ]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->roles()->attach($adminRole);

        $viewerRole = Role::factory()->create([
            'slug' => 'viewer',
            'permissions' => ['menus.view'],
            'backend_access' => true,
            'is_active' => true,
        ]);
        $this->viewer = User::factory()->create(['is_active' => true]);
        $this->viewer->roles()->attach($viewerRole);
    }

    public function test_admin_can_list_menus(): void
    {
        Menu::create(['name' => 'Main', 'slug' => 'main', 'is_active' => true, 'items' => []]);

        $this->actingAs($this->admin)
            ->get(route('admin.menus.index'))
            ->assertOk();
    }

    public function test_admin_can_create_menu(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.menus.store'), [
            'name' => 'Footer',
            'slug' => 'footer',
            'is_active' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertTrue(Menu::where('slug', 'footer')->exists());
    }

    public function test_viewer_cannot_create_menu(): void
    {
        $this->actingAs($this->viewer)
            ->post(route('admin.menus.store'), [
                'name' => 'Nope',
                'slug' => 'nope',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_update_and_delete_menu(): void
    {
        $menu = Menu::create(['name' => 'Main', 'slug' => 'main', 'is_active' => true, 'items' => []]);

        $this->actingAs($this->admin)->put(route('admin.menus.update', $menu->id), [
            'name' => 'Main Nav',
            'slug' => 'main',
            'is_active' => true,
            // Payload shape sent by Menus/Edit.tsx when editing a seeded menu (includes `order`)
            'items' => [[
                'id' => 'a1',
                'title' => 'Home',
                'url' => '/',
                'target' => '_self',
                'order' => 1,
                'subItems' => [['id' => 'b1', 'title' => 'Sub', 'url' => '/sub', 'target' => '_blank', 'order' => 2]],
            ]],
        ])->assertSessionHasNoErrors();

        $saved = Menu::where('name', 'Main Nav')->firstOrFail();
        $this->assertSame('a1', $saved->items[0]['id']);
        $this->assertSame('Home', $saved->items[0]['title']);
        $this->assertEquals(1, $saved->items[0]['order']);
        $this->assertSame('Sub', $saved->items[0]['subItems'][0]['title']);
        $this->assertSame('_blank', $saved->items[0]['subItems'][0]['target']);
        $this->assertEquals(2, $saved->items[0]['subItems'][0]['order']);

        $this->actingAs($this->admin)
            ->delete(route('admin.menus.destroy', $menu->id))
            ->assertRedirect();

        $this->assertFalse(Menu::where('slug', 'main')->exists());
    }

    public function test_sub_item_url_rejects_dangerous_scheme(): void
    {
        $menu = Menu::create(['name' => 'Main', 'slug' => 'main', 'is_active' => true, 'items' => []]);

        $this->actingAs($this->admin)->put(route('admin.menus.update', $menu->id), [
            'name' => 'Main',
            'slug' => 'main',
            'items' => [[
                'title' => 'Home',
                'url' => '/',
                'subItems' => [['title' => 'XSS', 'url' => 'javascript:alert(1)']],
            ]],
        ])->assertSessionHasErrors('items.0.subItems.0.url');
    }
}
