import type { LucideIcon } from 'lucide-react';

export interface NavItem {
    label: string;
    href: string;
    icon?: LucideIcon;
    /**
     * Not enforced yet — no permission system exists (Phase 1 staff
     * roles/permissions land in a later task). Items without this render
     * unconditionally; it exists so admin navigation is ready to filter on
     * once real permission data is available.
     */
    requiredPermission?: string;
}

export interface NavGroup {
    label?: string;
    items: NavItem[];
}
