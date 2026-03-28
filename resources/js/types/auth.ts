export type BusinessSummary = {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    role?: string | null;
    membership_is_active?: boolean | null;
};

export type User = {
    id: number;
    name: string;
    email: string;
    locale: string;
    backoffice_role: string | null;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    is_platform_admin: boolean;
    backoffice: {
        can_access: boolean;
        can_manage_users: boolean;
        can_prepare_businesses: boolean;
        can_access_dashboard: boolean;
        can_view_analytics: boolean;
        role_label?: string | null;
    };
    current_business: BusinessSummary | null;
    businesses: BusinessSummary[];
    [key: string]: unknown;
};

export type Auth = {
    user: User | null;
};

export type Flash = {
    success?: string;
    error?: string;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
