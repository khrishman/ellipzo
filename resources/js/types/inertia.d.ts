import '@inertiajs/core';

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    emailVerifiedAt: string | null;
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            auth: {
                user: AuthUser | null;
                /** Display-only. See HandleInertiaRequests::share(). */
                permissions: string[];
                /** 'active' | 'limited' | 'suspended' | 'closed', or undefined when logged out. */
                accountStatus?: string;
            };
        };
    }
}
