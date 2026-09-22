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

/** Where a Revision sits in its life. Only released Revisions reach RPA-TOOL by default. */
export type RevisionStatus = 'draft' | 'released' | 'deprecated';

/**
 * An immutable, sequentially numbered upload of an AI Model or FlowChart Script,
 * carrying a change note and the Team Member who uploaded it.
 */
export interface Revision {
    id: string;
    revisable_type: 'flowchart_script' | 'ai_model';
    revisable_id: string;
    number: number;
    status: RevisionStatus;
    change_note: string;
    original_filename: string;
    disk_path?: string;
    size_bytes: number;
    sha256: string;
    mime?: string | null;
    uploaded_by?: string | null;
    released_by?: string | null;
    released_at?: string | null;
    deprecated_at?: string | null;
    uploader?: Pick<User, 'id' | 'name'> | null;
    releaser?: Pick<User, 'id' | 'name'> | null;
    downloads_count?: number;
    created_at?: string;
}

/** A picture attached to a FlowChart Script so it can be recognised before downloading. */
export interface FlowchartScriptImage {
    id: string;
    flowchart_script_id: string;
    vault_file_id: string;
    sort_order: number;
    vault_file?: {
        id: string;
        uuid: string;
        original_name: string;
        mime_type: string;
        url: string;
    } | null;
}

/** A packaged automation sequence for one Machine Model. */
export interface FlowchartScript {
    id: string;
    machine_model_id: string;
    customer_id?: string | null;
    name: string;
    slug: string;
    description?: string | null;
    created_by?: string | null;
    deleted_at?: string | null;
    machine_model?: (Pick<MachineModel, 'id' | 'name' | 'machine_brand_id'> & {
        machine_brand?: Pick<MachineBrand, 'id' | 'name'> | null;
    }) | null;
    customer?: Pick<Customer, 'id' | 'company'> | null;
    creator?: Pick<User, 'id' | 'name'> | null;
    images?: FlowchartScriptImage[];
    revisions_count?: number;
    downloads_count?: number;
    /** Highest released Revision number, or null when nothing has been released. */
    latest_released_number?: number | null;
    created_at?: string;
    updated_at?: string;
}

/** A trained inference model published on its own, independent of any FlowChart Script. */
export interface AiModel {
    id: string;
    machine_model_id: string;
    customer_id?: string | null;
    name: string;
    slug: string;
    description?: string | null;
    framework?: string | null;
    input_size?: string | null;
    labels?: string | null;
    notes?: string | null;
    created_by?: string | null;
    deleted_at?: string | null;
    machine_model?: (Pick<MachineModel, 'id' | 'name' | 'machine_brand_id'> & {
        machine_brand?: Pick<MachineBrand, 'id' | 'name'> | null;
    }) | null;
    customer?: Pick<Customer, 'id' | 'company'> | null;
    creator?: Pick<User, 'id' | 'name'> | null;
    revisions_count?: number;
    downloads_count?: number;
    latest_released_number?: number | null;
    created_at?: string;
    updated_at?: string;
}

/** One recorded fetch of a Revision's file. */
export interface Download {
    id: string;
    revision_id: string;
    revisable_type: 'flowchart_script' | 'ai_model';
    revisable_id: string;
    user_id?: string | null;
    ai_box_id?: string | null;
    source: 'api' | 'web';
    ip?: string | null;
    user_agent?: string | null;
    user?: Pick<User, 'id' | 'name'> | null;
    revision?: Pick<Revision, 'id' | 'number'> | null;
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
    canUpload?: boolean;
    canRelease?: boolean;
    canHardDelete?: boolean;
    passwordRulesString?: string;
};

declare module 'react' {
    interface InputHTMLAttributes<T> extends HTMLAttributes<T> {
        passwordrules?: string;
    }
}
