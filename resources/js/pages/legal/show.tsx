import { Head } from '@inertiajs/react';
import type { ReactElement, ReactNode } from 'react';

import PublicLayout from '@/layouts/public-layout';

interface LegalShowProps {
    document: {
        slug: string;
        title: string;
        version: string;
        published: boolean;
    };
}

export default function LegalShow({ document }: LegalShowProps) {
    return (
        <>
            <Head title={document.title} />
            <div className="mx-auto max-w-[760px] px-4 py-16 sm:px-6 lg:px-8">
                <h1 className="text-h1 text-neutral-900">{document.title}</h1>
                <p className="text-body-sm mt-2 text-neutral-500">Version {document.version}</p>

                {document.published ? (
                    <p className="text-body-lg mt-6 text-neutral-600">This document is being finalized for display.</p>
                ) : (
                    <p className="text-body-sm mt-6 rounded-md border border-status-neutral-border bg-status-neutral-bg p-4 text-status-neutral-text">
                        This is a draft placeholder. Ellipzo&apos;s final, reviewed {document.title.toLowerCase()} has not been published yet.
                    </p>
                )}
            </div>
        </>
    );
}

LegalShow.layout = (page: ReactElement) => <PublicLayout>{page as ReactNode}</PublicLayout>;
