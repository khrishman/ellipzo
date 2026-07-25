import { Head, useForm } from '@inertiajs/react';
import { type FormEvent, type ReactElement, type ReactNode, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AdminLayout from '@/layouts/admin-layout';

interface StaffMember {
    id: number;
    name: string;
    email: string;
    role: string | null;
}

interface AuditRow {
    actor: string | null;
    target: string;
    beforeRole: string | null;
    afterRole: string | null;
    reason: string;
    occurredAt: string | null;
}

interface StaffAccessProps {
    staff: StaffMember[];
    roles: string[];
    canManage: boolean;
    canViewAudit: boolean;
    recentAuditEvents: AuditRow[];
}

const NO_ROLE_VALUE = '';

function roleLabel(role: string | null): string {
    if (!role) {
        return 'No staff role';
    }
    return role
        .split('-')
        .map((word) => word[0].toUpperCase() + word.slice(1))
        .join(' ');
}

export default function StaffAccess({ staff, roles, canManage, canViewAudit, recentAuditEvents }: StaffAccessProps) {
    const [isConfirmOpen, setIsConfirmOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        role: NO_ROLE_VALUE,
        reason: '',
    });

    const canOpenConfirm = data.email.trim() !== '' && data.reason.trim() !== '';

    function handleSaveClick(event: FormEvent) {
        event.preventDefault();
        if (canOpenConfirm) {
            setIsConfirmOpen(true);
        }
    }

    function handleConfirm() {
        post('/admin/staff-access', {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setIsConfirmOpen(false);
            },
            onError: () => setIsConfirmOpen(false),
        });
    }

    return (
        <>
            <Head title="Staff access" />
            <div className="space-y-8">
                <div>
                    <h1 className="text-h1 text-neutral-900">Staff access</h1>
                    <p className="text-body mt-1 text-neutral-600">Assign one of the predefined staff roles to an existing account.</p>
                </div>

                {canManage && (
                    <form onSubmit={handleSaveClick} className="max-w-lg space-y-4 rounded-lg border border-neutral-200 bg-white p-6">
                        <div>
                            <label htmlFor="email" className="text-label block text-neutral-700">
                                User email
                            </label>
                            <input
                                id="email"
                                type="email"
                                required
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                aria-invalid={errors.email ? 'true' : undefined}
                                className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900"
                            />
                            {errors.email && <p className="text-body-sm mt-1.5 text-danger-text">{errors.email}</p>}
                        </div>

                        <div>
                            <label htmlFor="role" className="text-label block text-neutral-700">
                                Role
                            </label>
                            <select
                                id="role"
                                value={data.role}
                                onChange={(e) => setData('role', e.target.value)}
                                aria-invalid={errors.role ? 'true' : undefined}
                                className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900"
                            >
                                <option value={NO_ROLE_VALUE}>No staff role (remove access)</option>
                                {roles.map((role) => (
                                    <option key={role} value={role}>
                                        {roleLabel(role)}
                                    </option>
                                ))}
                            </select>
                            {errors.role && <p className="text-body-sm mt-1.5 text-danger-text">{errors.role}</p>}
                        </div>

                        <div>
                            <label htmlFor="reason" className="text-label block text-neutral-700">
                                Reason
                            </label>
                            <textarea
                                id="reason"
                                required
                                minLength={10}
                                rows={3}
                                value={data.reason}
                                onChange={(e) => setData('reason', e.target.value)}
                                aria-invalid={errors.reason ? 'true' : undefined}
                                className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900"
                            />
                            {errors.reason && <p className="text-body-sm mt-1.5 text-danger-text">{errors.reason}</p>}
                        </div>

                        <Button type="submit" disabled={!canOpenConfirm || processing}>
                            Save role
                        </Button>
                    </form>
                )}

                <Dialog open={isConfirmOpen} onOpenChange={setIsConfirmOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Confirm staff role change</DialogTitle>
                            <DialogDescription>
                                You are about to set the staff role for <strong>{data.email}</strong> to{' '}
                                <strong>{roleLabel(data.role || null)}</strong>. This takes effect immediately and is recorded in the audit trail.
                            </DialogDescription>
                        </DialogHeader>
                        <p className="text-body-sm text-neutral-600">Reason: {data.reason}</p>
                        <DialogFooter>
                            <Button type="button" variant="secondary" onClick={() => setIsConfirmOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="button" onClick={handleConfirm} disabled={processing}>
                                Confirm change
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <div>
                    <h2 className="text-h3 text-neutral-900">Current staff</h2>
                    <div className="mt-3 overflow-x-auto rounded-lg border border-neutral-200 bg-white">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-neutral-200 bg-neutral-50">
                                <tr>
                                    <th className="px-4 py-2 font-medium text-neutral-500">Name</th>
                                    <th className="px-4 py-2 font-medium text-neutral-500">Email</th>
                                    <th className="px-4 py-2 font-medium text-neutral-500">Role</th>
                                </tr>
                            </thead>
                            <tbody>
                                {staff.length === 0 && (
                                    <tr>
                                        <td colSpan={3} className="px-4 py-3 text-neutral-500">
                                            No staff members yet.
                                        </td>
                                    </tr>
                                )}
                                {staff.map((member) => (
                                    <tr key={member.id} className="border-b border-neutral-100 last:border-0">
                                        <td className="px-4 py-2 text-neutral-900">{member.name}</td>
                                        <td className="px-4 py-2 text-neutral-600">{member.email}</td>
                                        <td className="px-4 py-2 text-neutral-600">{roleLabel(member.role)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {canViewAudit && (
                    <div>
                        <h2 className="text-h3 text-neutral-900">Recent role changes</h2>
                        <div className="mt-3 overflow-x-auto rounded-lg border border-neutral-200 bg-white">
                            <table className="w-full text-left text-sm">
                                <thead className="border-b border-neutral-200 bg-neutral-50">
                                    <tr>
                                        <th className="px-4 py-2 font-medium text-neutral-500">Target</th>
                                        <th className="px-4 py-2 font-medium text-neutral-500">Change</th>
                                        <th className="px-4 py-2 font-medium text-neutral-500">Reason</th>
                                        <th className="px-4 py-2 font-medium text-neutral-500">By</th>
                                        <th className="px-4 py-2 font-medium text-neutral-500">When</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recentAuditEvents.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="px-4 py-3 text-neutral-500">
                                                No role changes recorded yet.
                                            </td>
                                        </tr>
                                    )}
                                    {recentAuditEvents.map((event, index) => (
                                        <tr key={index} className="border-b border-neutral-100 last:border-0">
                                            <td className="px-4 py-2 text-neutral-900">{event.target}</td>
                                            <td className="px-4 py-2 text-neutral-600">
                                                {roleLabel(event.beforeRole)} → {roleLabel(event.afterRole)}
                                            </td>
                                            <td className="px-4 py-2 text-neutral-600">{event.reason}</td>
                                            <td className="px-4 py-2 text-neutral-600">{event.actor}</td>
                                            <td className="px-4 py-2 text-neutral-600">{event.occurredAt}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

StaffAccess.layout = (page: ReactElement) => <AdminLayout pageTitle="Staff access">{page as ReactNode}</AdminLayout>;
