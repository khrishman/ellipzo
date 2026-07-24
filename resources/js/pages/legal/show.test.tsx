import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
}));

import LegalShow from './show';

describe('LegalShow page', () => {
    it('renders the document title and version', () => {
        render(<LegalShow document={{ slug: 'terms', title: 'Terms of Service', version: '2026-07-24', published: false }} />);

        expect(screen.getByRole('heading', { name: 'Terms of Service' })).toBeInTheDocument();
        expect(screen.getByText('Version 2026-07-24')).toBeInTheDocument();
    });

    it('shows an honest draft notice for an unpublished document', () => {
        render(<LegalShow document={{ slug: 'terms', title: 'Terms of Service', version: '2026-07-24', published: false }} />);

        expect(screen.getByText(/draft placeholder/i)).toBeInTheDocument();
        expect(screen.getByText(/has not been published yet/i)).toBeInTheDocument();
    });

    it('does not show the draft notice for a published document', () => {
        render(<LegalShow document={{ slug: 'terms', title: 'Terms of Service', version: '2026-07-24', published: true }} />);

        expect(screen.queryByText(/draft placeholder/i)).not.toBeInTheDocument();
    });
});
