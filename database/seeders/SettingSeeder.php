<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // General
            [
                'key' => 'site_name',
                'value' => 'Unysis Marketplace',
                'group' => 'general',
                'type' => 'text',
                'label' => 'Site Name',
                'is_public' => true,
            ],
            [
                'key' => 'site_description',
                'value' => 'Internal catalogue of the AI Models and Scripts that run on UNYSIS Boxes.',
                'group' => 'general',
                'type' => 'textarea',
                'label' => 'Site Description',
                'is_public' => true,
            ],

            // Branding
            [
                'key' => 'auth_image_url',
                'value' => '/images/auth-bg.jpg',
                'group' => 'branding',
                'type' => 'image',
                'label' => 'Auth Background Image',
                'is_public' => true,
            ],

            // Integrations
            [
                'key' => 'social_login_google_enabled',
                'value' => false,
                'group' => 'auth',
                'type' => 'boolean',
                'label' => 'Enable Google Login',
                'description' => 'Requires GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in your .env file.',
                'is_public' => true,
            ],
            [
                'key' => 'social_login_github_enabled',
                'value' => false,
                'group' => 'auth',
                'type' => 'boolean',
                'label' => 'Enable GitHub Login',
                'description' => 'Requires GITHUB_CLIENT_ID and GITHUB_CLIENT_SECRET in your .env file.',
                'is_public' => true,
            ],
            [
                'key' => 'social_login_apple_enabled',
                'value' => false,
                'group' => 'auth',
                'type' => 'boolean',
                'label' => 'Enable Apple Login',
                'description' => 'Coming soon. Requires Apple Developer account credentials.',
                'is_public' => true,
            ],
            [
                'key' => 'social_login_twitter_enabled',
                'value' => false,
                'group' => 'auth',
                'type' => 'boolean',
                'label' => 'Enable Twitter (X) Login',
                'description' => 'Coming soon. Requires Twitter Developer App credentials.',
                'is_public' => true,
            ],

            // Auth
            [
                'key' => 'auth.registration_enabled',
                'value' => false,
                'group' => 'auth',
                'type' => 'boolean',
                'label' => 'Enable Self-Registration',
                'description' => 'Allow new users to register via the /register page. When disabled, only admin-invited users can create accounts.',
                'is_public' => false,
            ],

            // System
            [
                'key' => 'maintenance_mode',
                'value' => false,
                'group' => 'system',
                'type' => 'boolean',
                'label' => 'Maintenance Mode',
                'is_public' => true, // Might need middleware logic for this
            ],

            // Media / Vault
            [
                'key' => 'vault.webp_conversion',
                'value' => true,
                'group' => 'media',
                'type' => 'boolean',
                'label' => 'Convert Uploads to WebP',
                'description' => 'Automatically convert JPEG and PNG uploads to WebP for smaller file sizes. GIFs are not affected.',
                'is_public' => false,
            ],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
