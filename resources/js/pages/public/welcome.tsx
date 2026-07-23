import { Head } from '@inertiajs/react';

interface WelcomeProps {
    laravelVersion: string;
    phpVersion: string;
}

export default function Welcome({ laravelVersion, phpVersion }: WelcomeProps) {
    return (
        <>
            <Head title="Welcome" />
            <main className="flex min-h-screen items-center justify-center bg-neutral-50 px-4">
                <div className="max-w-md rounded-xl border border-neutral-200 bg-white p-8 text-center shadow-sm">
                    <h1 className="text-2xl font-bold text-neutral-900">Ellipzo foundation is running</h1>
                    <p className="mt-3 text-sm text-neutral-600">
                        Laravel {laravelVersion} &middot; PHP {phpVersion} &middot; React + Inertia + Tailwind
                    </p>
                </div>
            </main>
        </>
    );
}
