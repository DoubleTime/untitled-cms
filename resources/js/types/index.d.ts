export interface Role {
    id: string;
    name: string;
    slug: string;
}

export interface User {
    id: string; // ULID (26-char, lexically sortable)
    name: string;
    email: string;
    email_verified_at?: string;
    is_active: boolean;
    deleted_at?: string | null;
    roles?: Role[];
    permissions: string[];
}

export interface SharedSettings {
    social_login_google_enabled?: boolean;
    social_login_github_enabled?: boolean;
    social_login_apple_enabled?: boolean;
    social_login_twitter_enabled?: boolean;
    [key: string]: unknown;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    appName: string;
    auth: {
        user: User;
        permissions: string[];
    };
    settings: SharedSettings;
    tinymce_api_key?: string;
    canCreate?: boolean;
    canEdit?: boolean;
    canDelete?: boolean;
    passwordRulesString?: string;
};

declare module 'react' {
    interface InputHTMLAttributes<T> extends HTMLAttributes<T> {
        passwordrules?: string;
    }
}
