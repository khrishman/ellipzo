<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reconciles the project's own reference roles and permissions to their
 * documented definition (prd.md §6.3, Architecture.md §9). Idempotent and
 * safe to rerun: only the four named roles and the permission strings
 * below are ever touched. An operator-created role outside this list, or
 * a permission this seeder does not know about, is never read, modified,
 * or deleted.
 *
 * Several permissions here (users.view, campaigns.moderate, and the rest
 * of the "future-domain" block below) are seeded because Architecture.md
 * §9 and the admin navigation already reference them, but no controller
 * or middleware in this codebase checks them yet - they stay inert until
 * their owning feature exists, exactly like the nav items that reference
 * them (see resources/js/components/domain/admin-nav.tsx).
 */
class RolePermissionSeeder extends Seeder
{
    private const GUARD = 'web';

    /** Permissions Task 9/10 actually enforce. */
    private const ACTIVE_PERMISSIONS = [
        'admin.overview.view',
        'staff.view',
        'staff.manage',
        'audit.view',
        'users.status.manage',
    ];

    /** Referenced by prd.md/Architecture.md/admin-nav.tsx; not yet enforced anywhere. */
    private const FUTURE_PERMISSIONS = [
        'users.view',
        'users.limit',
        'campaigns.moderate',
        'submissions.moderate',
        'disputes.resolve',
        'deposits.review',
        'withdrawals.review',
        'withdrawals.approve',
        'ledger.adjust',
        'settings.manage',
        'support.view',
    ];

    /**
     * Each role maps to the smallest documented permission set (prd.md
     * §6.3). Only Administrator receives staff.manage.
     */
    private const ROLE_PERMISSIONS = [
        'administrator' => [
            'admin.overview.view', 'staff.view', 'staff.manage', 'audit.view',
            'users.view', 'users.limit', 'users.status.manage', 'campaigns.moderate', 'submissions.moderate',
            'disputes.resolve', 'deposits.review', 'withdrawals.review',
            'withdrawals.approve', 'ledger.adjust', 'settings.manage', 'support.view',
        ],
        'moderator' => [
            'admin.overview.view', 'campaigns.moderate', 'submissions.moderate', 'disputes.resolve',
        ],
        'finance-operator' => [
            'admin.overview.view', 'deposits.review', 'withdrawals.review',
            'withdrawals.approve', 'ledger.adjust',
        ],
        'support-agent' => [
            'admin.overview.view', 'support.view',
        ],
    ];

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach ([...self::ACTIVE_PERMISSIONS, ...self::FUTURE_PERMISSIONS] as $name) {
            Permission::findOrCreate($name, self::GUARD);
        }

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, self::GUARD);
            $role->syncPermissions($permissions);
        }

        $registrar->forgetCachedPermissions();
    }
}
