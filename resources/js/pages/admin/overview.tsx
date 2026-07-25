import { Head, Link } from '@inertiajs/react';
import type { ReactElement, ReactNode } from 'react';

import AdminLayout from '@/layouts/admin-layout';

interface AdminOverviewProps {
    totalUsers: number;
    totalStaff: number;
}

export default function AdminOverview({ totalUsers, totalStaff }: AdminOverviewProps) {
    return (
        <>
            <Head title="Admin overview" />
            <div className="space-y-6">
                <div>
                    <h1 className="text-h1 text-neutral-900">Overview</h1>
                    <p className="text-body mt-1 text-neutral-600">
                        Campaign, submission, finance, and risk dashboards are not built yet. This page shows only what is genuinely available today.
                    </p>
                </div>

                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="rounded-lg border border-neutral-200 bg-white p-6">
                        <dt className="text-body-sm text-neutral-500">Total registered users</dt>
                        <dd className="text-h2 mt-1 text-neutral-900">{totalUsers}</dd>
                    </div>
                    <div className="rounded-lg border border-neutral-200 bg-white p-6">
                        <dt className="text-body-sm text-neutral-500">Total staff members</dt>
                        <dd className="text-h2 mt-1 text-neutral-900">{totalStaff}</dd>
                    </div>
                </dl>

                <Link href="/admin/staff-access" className="focus-ring text-body-sm inline-block font-medium text-brand-700 hover:underline">
                    Manage staff access →
                </Link>
            </div>
        </>
    );
}

AdminOverview.layout = (page: ReactElement) => <AdminLayout pageTitle="Overview">{page as ReactNode}</AdminLayout>;
