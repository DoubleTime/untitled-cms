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

/**
 * Marketplace — vocabulary is fixed in CONTEXT.md.
 */

/** A reusable tag naming the manufacturer of a Machine Model. */
export interface MachineBrand {
    id: string;
    name: string;
    slug: string;
    machine_models_count?: number;
    created_at?: string;
    updated_at?: string;
}

/** A specific make of equipment that catalogue entries target. */
export interface MachineModel {
    id: string;
    machine_brand_id: string;
    name: string;
    slug: string;
    description?: string | null;
    is_active: boolean;
    machine_brand?: Pick<MachineBrand, 'id' | 'name'> | null;
    flowchart_scripts_count?: number;
    ai_models_count?: number;
    created_at?: string;
    updated_at?: string;
}

/** A company that owns AI Boxes and has its own Customer Users. */
export interface Customer {
    id: string;
    name: string;
    company: string;
    contact_name?: string | null;
    contact_email?: string | null;
    contact_phone?: string | null;
    notes?: string | null;
    is_active: boolean;
    users_count?: number;
    ai_boxes_count?: number;
    created_at?: string;
    updated_at?: string;
}

/**
 * A person belonging to one Customer who signs in through RPA-TOOL.
 * Never has access to the Marketplace admin.
 */
export interface CustomerUser {
    id: string;
    name: string;
    email: string;
    is_active: boolean;
    customer_id: string;
    /** Live Sanctum tokens — one per AI Box session. */
    tokens_count: number;
    created_at?: string;
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
    flash?: {
        success?: string | null;
        error?: string | null;
    };
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
