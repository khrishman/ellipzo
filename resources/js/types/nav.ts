import type { LucideIcon } from 'lucide-react';

export interface NavItem {
    label: string;
    href: string;
    icon?: LucideIcon;
    /**
     * Display-only filtering, never an authorization boundary. Items
     * without this render unconditionally; every route this links to
     * independently re-checks the same permission server-side.
     */
    requiredPermission?: string;
    /**
     * Set to false when the linked route does not exist yet. Renders as a
     * disabled, non-interactive item instead of a link, so an unfinished
     * section is never one click away from a 404. Defaults to true.
     */
    implemented?: boolean;
}

export interface NavGroup {
    label?: string;
    items: NavItem[];
}
