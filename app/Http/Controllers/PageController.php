<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePageRequest;
use App\Http\Requests\UpdatePageRequest;
use App\Models\Page;
use App\Models\Redirect as PageRedirect;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use League\HTMLToMarkdown\HtmlConverter;

class PageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $this->authorize('viewAny', Page::class);

        $pages = Page::orderBy('created_at', 'desc')->paginate(10);

        return Inertia::render('Pages/Index', [
            'pages' => $pages,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $this->authorize('create', Page::class);

        return Inertia::render('Pages/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePageRequest $request)
    {
        $validated = $request->validated();

        // Derive base slug from custom input or title
        $baseSlug = ! empty($validated['slug'])
            ? Str::slug($validated['slug'])
            : Str::slug($validated['title']);

        $slug = Page::uniqueSlug($baseSlug);

        // Sanitize content. An empty editor arrives as null (ConvertEmptyStringsToNull).
        $validated['content'] = clean($validated['content'] ?? '');

        $page = Page::create([
            ...$validated,
            'slug' => $slug,
            'author_id' => auth()->id(),
            'published_at' => $validated['status'] === 'published' ? now() : null,
        ]);

        ActivityLogger::log('create', "Created page: {$page->title}", $page);

        return redirect()->route('admin.pages.index')->with('success', 'Page created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $page = Page::findOrFail($id);
        $this->authorize('view', $page);

        if ($request->prefers(['text/html', 'text/markdown']) === 'text/markdown') {
            $converter = new HtmlConverter;
            $markdownContent = $converter->convert($page->content ?? '');

            $frontmatter = "---\n";
            $title = str_replace('"', '\"', $page->seo_title ?: $page->title);
            $frontmatter .= "title: \"{$title}\"\n";
            if ($page->seo_description) {
                $description = str_replace('"', '\"', $page->seo_description);
                $frontmatter .= "description: \"{$description}\"\n";
            }
            if (! empty($page->featured_images)) {
                $frontmatter .= 'image: '.url($page->featured_images[0])."\n";
            }
            $frontmatter .= "---\n\n";

            $markdown = $frontmatter.'# '.$page->title."\n\n".$markdownContent;

            return response($markdown, 200)
                ->header('Content-Type', 'text/markdown; charset=utf-8')
                ->header('Content-Signal', 'ai-train=yes, search=yes, ai-input=yes')
                ->header('x-markdown-tokens', round(strlen($markdown) / 4));
        }

        // Optional: Could return an Inertia view for a full admin preview,
        // but for now, we just support the markdown preview endpoint.
        abort(404, 'Preview only supports text/markdown currently.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $page = Page::findOrFail($id);
        $this->authorize('update', $page);

        // Get redirects pointing to this page's current slug
        $redirects = PageRedirect::where('to_path', $page->slug)
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Pages/Edit', [
            'page' => $page,
            'redirects' => $redirects,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePageRequest $request, Page $page)
    {
        $validated = $request->validated();

        $baseSlug = ! empty($validated['slug'])
            ? Str::slug($validated['slug'])
            : Str::slug($validated['title']);

        $validated['slug'] = Page::uniqueSlug($baseSlug, $page->id);

        if (isset($validated['status']) && $validated['status'] === 'published' && $page->status !== 'published') {
            $validated['published_at'] = now();
        }

        // Check for slug change and create redirect
        if ($page->slug !== $validated['slug']) {
            // Create a redirect from the old slug to the new slug (from_path is unique, so
            // reusing a former slug must update the existing redirect rather than insert a duplicate)
            PageRedirect::updateOrCreate(
                ['from_path' => $page->slug],
                [
                    'to_path' => $validated['slug'],
                    'type' => 301,
                    'active' => true,
                ]
            );

            // Update any existing redirects that pointed to the old slug to point to the new slug
            // (Avoid daisy-chaining)
            PageRedirect::where('to_path', $page->slug)->update([
                'to_path' => $validated['slug'],
            ]);
        }

        // Only touch content when it was submitted, so a partial update can't blank the page.
        if (array_key_exists('content', $validated)) {
            $validated['content'] = clean($validated['content'] ?? '');
        }

        $page->update($validated);

        ActivityLogger::log('update', "Updated page: {$page->title}", $page);

        if ($request->has('stay')) {
            return redirect()->back()->with('success', 'Page status updated successfully.');
        }

        return redirect()->route('admin.pages.index')->with('success', 'Page updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Page $page)
    {
        $this->authorize('delete', $page);

        $title = $page->title;
        $page->delete();

        ActivityLogger::log('delete', "Deleted page: {$title}", $page);

        return redirect()->route('admin.pages.index')->with('success', 'Page deleted successfully.');
    }
}
