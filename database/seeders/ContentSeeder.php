<?php

namespace Database\Seeders;

use App\Models\Banner;
use App\Models\Page;
use App\Models\User;
use Illuminate\Database\Seeder;

class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@example.com')->first();
        $authorId = $admin ? $admin->id : null;

        // Sample Banners
        Banner::updateOrCreate(['title' => 'Welcome to Our CMS'], [
            'image_url' => 'https://images.unsplash.com/photo-1499750310159-5b600aafb1d6?ixlib=rb-4.0.3&auto=format&fit=crop&w=2070&q=80',
            'alt_text' => 'Workspace desk with laptop',
            'description' => 'Built with Laravel 13, PostgreSQL, and Inertia.js',
            'link_url' => '/about-us',
            'order' => 1,
            'is_active' => true,
        ]);

        Banner::updateOrCreate(['title' => 'Modern Web Development'], [
            'image_url' => 'https://images.unsplash.com/photo-1542831371-29b0f74f9713?ixlib=rb-4.0.3&auto=format&fit=crop&w=2070&q=80',
            'alt_text' => 'Code on screen',
            'description' => 'Efficient, scalable, and maintainable.',
            'order' => 2,
            'is_active' => true,
        ]);

        // Sample Pages — keyed by slug to prevent duplicates on re-run
        Page::updateOrCreate(['slug' => 'getting-started'], [
            'title' => 'Getting Started with Untitled CMS',
            'content' => '<h2>Introduction</h2><p>Untitled CMS is an AI-native content management system built on <strong>Laravel 13</strong>, <strong>PostgreSQL</strong>, and a <strong>React + Inertia.js</strong> admin SPA. It is designed to be lightweight yet powerful, with a flexible schema perfect for dynamic content structures.</p><h3>Key Features</h3><ul><li>Role-Based Access Control (RBAC) with 34 granular permissions</li><li>Rich Text Editing with CKEditor 5</li><li>The Vault — hierarchical media manager with a secure 7-stage upload pipeline</li><li>Multi-provider AI Hub (OpenAI, Anthropic, Gemini, Groq, Mistral, Deepseek, Ollama)</li><li>Markdown-for-Agents: every page responds with <code>Accept: text/markdown</code></li><li><code>/llms.txt</code> and <code>/llms-full.txt</code> for AI discoverability</li></ul><p>Explore the admin panel to see how easy it is to manage your digital presence.</p>',
            'status' => 'published',
            'author_id' => $authorId,
            'published_at' => now(),
            'seo_title' => 'Getting Started with Untitled CMS',
            'seo_description' => 'Learn how to set up and use Untitled CMS — an AI-native CMS built on Laravel 13, PostgreSQL, and React.',
        ]);

        Page::updateOrCreate(['slug' => 'power-of-inertia'], [
            'title' => 'The Power of Inertia.js',
            'content' => '<h2>Why Inertia?</h2><p>Inertia.js allows you to build single-page apps without building an API. It works like a classic server-side framework but gives you the smooth SPA experience.</p><blockquote>"Build monoliths, not APIs."</blockquote><p>Combined with React and Shadcn UI, we get a modern, accessible, and fast interface for both administrators and visitors. Untitled CMS uses Inertia to share data as props directly from Laravel controllers — no REST endpoints, no client-side data fetching.</p>',
            'status' => 'published',
            'author_id' => $authorId,
            'published_at' => now()->subDay(),
            'seo_title' => 'Why We Chose Inertia.js',
            'seo_description' => 'Discover the benefits of using Inertia.js for modern web application development.',
        ]);

        Page::updateOrCreate(['slug' => 'draft-example'], [
            'title' => 'Draft Page Example',
            'content' => '<p>This is a draft page. It will not be visible on the public frontend until published.</p>',
            'status' => 'draft',
            'author_id' => $authorId,
        ]);

        Page::updateOrCreate(['slug' => 'privacy'], [
            'title' => 'Privacy Policy',
            'content' => '<h2>Privacy Policy</h2><p>We respect your privacy and are committed to protecting your personal data. This privacy policy explains how we collect, use, and safeguard information when you visit our website.</p><h3>Information We Collect</h3><p>We may collect information you provide directly to us, such as when you create an account, contact us, or use our services. This may include your name, email address, and any other information you choose to provide.</p><h3>How We Use Your Information</h3><ul><li>To provide, maintain, and improve our services</li><li>To send you technical notices and support messages</li><li>To respond to your comments and questions</li><li>To monitor and analyse usage patterns</li></ul><h3>Data Retention</h3><p>We retain personal data for as long as necessary to fulfil the purposes outlined in this policy, unless a longer retention period is required by law.</p><h3>Your Rights</h3><p>Depending on your location, you may have rights regarding your personal data, including the right to access, correct, or delete data we hold about you. Contact us to exercise these rights.</p><h3>Contact Us</h3><p>If you have questions about this privacy policy or our data practices, please contact us through the details on our website.</p>',
            'status' => 'published',
            'author_id' => $authorId,
            'published_at' => now(),
            'seo_title' => 'Privacy Policy',
            'seo_description' => 'Our privacy policy explains how we collect, use, and protect your personal data.',
            'is_system_page' => true,
        ]);

        Page::updateOrCreate(['slug' => 'terms'], [
            'title' => 'Terms of Service',
            'content' => '<h2>Terms of Service</h2><p>By accessing or using our service, you agree to be bound by these terms. Please read them carefully before using the platform.</p><h3>Use of Service</h3><p>You may use our service only for lawful purposes and in accordance with these terms. You agree not to use the service in any way that violates applicable laws or regulations, or to transmit any harmful or disruptive content.</p><h3>Accounts</h3><p>When you create an account, you are responsible for maintaining the confidentiality of your credentials and for all activity that occurs under your account. Notify us immediately of any unauthorised use.</p><h3>Intellectual Property</h3><p>The service and its original content, features, and functionality are owned by us and are protected by copyright, trademark, and other intellectual property laws. Content you publish remains yours — you grant us a licence to host and display it.</p><h3>Termination</h3><p>We reserve the right to suspend or terminate your access to the service at our sole discretion, without notice, for conduct that we believe violates these terms or is harmful to other users, us, or third parties.</p><h3>Disclaimer of Warranties</h3><p>The service is provided on an "as is" basis without warranties of any kind, either express or implied. We do not warrant that the service will be uninterrupted or error-free.</p><h3>Limitation of Liability</h3><p>To the fullest extent permitted by law, we shall not be liable for any indirect, incidental, special, consequential, or punitive damages arising from your use of the service.</p><h3>Changes to Terms</h3><p>We may update these terms from time to time. Continued use of the service after changes constitutes acceptance of the revised terms.</p><h3>Contact Us</h3><p>If you have questions about these terms, please reach out through our contact page.</p>',
            'status' => 'published',
            'author_id' => $authorId,
            'published_at' => now(),
            'seo_title' => 'Terms of Service',
            'seo_description' => 'Read our terms of service to understand the rules and guidelines for using our platform.',
            'is_system_page' => true,
        ]);
    }
}
