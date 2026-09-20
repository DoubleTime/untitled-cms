<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPageMarkdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_published_page_returns_html_by_default(): void
    {
        $page = Page::factory()->published()->create([
            'title' => 'Hello World',
            'slug' => 'hello-world',
            'content' => '<p>Body content</p>',
        ]);

        $response = $this->get('/'.$page->slug);

        $response->assertOk();
        $this->assertStringNotContainsString('text/markdown', (string) $response->headers->get('Content-Type'));
    }

    public function test_published_page_returns_markdown_when_accepted(): void
    {
        $page = Page::factory()->published()->create([
            'title' => 'Hello World',
            'slug' => 'hello-world',
            'content' => '<p>Body content</p>',
            'seo_title' => 'SEO Hello',
            'seo_description' => 'A description',
        ]);

        $response = $this->get('/'.$page->slug, [
            'Accept' => 'text/markdown',
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/markdown; charset=utf-8');
        $body = $response->getContent();
        $this->assertStringContainsString('title: "SEO Hello"', $body);
        $this->assertStringContainsString('description: "A description"', $body);
        $this->assertStringContainsString('Body content', $body);
    }

    public function test_draft_page_is_not_public(): void
    {
        $page = Page::factory()->create([
            'title' => 'Draft',
            'slug' => 'draft-page',
            'status' => 'draft',
        ]);

        $this->get('/'.$page->slug)->assertNotFound();
    }

    public function test_draft_preview_allowed_for_pages_view_permission(): void
    {
        $role = Role::factory()->create([
            'slug' => 'editor',
            'permissions' => ['pages.view'],
            'backend_access' => true,
            'is_active' => true,
        ]);
        $editor = User::factory()->create(['is_active' => true]);
        $editor->roles()->attach($role);

        $page = Page::factory()->create([
            'title' => 'Draft Preview',
            'slug' => 'draft-preview',
            'status' => 'draft',
            'content' => '<p>Secret draft</p>',
        ]);

        $this->actingAs($editor)
            ->get('/'.$page->slug.'?preview=1')
            ->assertOk();
    }

    public function test_draft_preview_denied_for_bare_authenticated_user(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $page = Page::factory()->create([
            'title' => 'Draft',
            'slug' => 'no-preview',
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->get('/'.$page->slug.'?preview=1')
            ->assertNotFound();
    }
}
