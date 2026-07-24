import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

interface AuthLayoutProps extends PropsWithChildren {
    title: string;
    description?: string;
}

export default function AuthLayout({ title, description, children }: AuthLayoutProps) {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-neutral-50 px-4 py-12">
            <div className="w-full max-w-sm">
                <div className="mb-8 flex justify-center">
                    <Link href="/" className="focus-ring text-h3 rounded-sm font-bold text-neutral-900">
                        Ellipzo
                    </Link>
                </div>

                <div className="shadow-card rounded-lg border border-neutral-200 bg-white p-6 sm:p-8">
                    <div className="mb-6 text-center">
                        <h1 className="text-h3 text-neutral-900">{title}</h1>
                        {description && <p className="text-body-sm mt-2 text-neutral-500">{description}</p>}
                    </div>
                    {children}
                </div>

                <p className="text-body-sm mt-6 text-center text-neutral-500">
                    <Link href="/" className="focus-ring rounded-sm text-neutral-700 hover:text-neutral-900">
                        Back to Ellipzo
                    </Link>
                </p>
            </div>
        </div>
    );
}
