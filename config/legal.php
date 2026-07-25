<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Legal Documents
    |--------------------------------------------------------------------------
    |
    | The single source of truth for which legal documents exist, which
    | version of each is current, and whether that version has actually
    | been reviewed and published. Consent version numbers are always
    | read from here - never from client input. A document with
    | "published" => false has draft/placeholder content only and must
    | not be recorded as accepted by anyone.
    |
    */

    'documents' => [
        'terms' => [
            'title' => 'Terms of Service',
            'version' => '2026-07-24',
            'published' => false,
        ],
        'privacy' => [
            'title' => 'Privacy Policy',
            'version' => '2026-07-24',
            'published' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Required Documents
    |--------------------------------------------------------------------------
    |
    | The single canonical list of document slugs that must be accepted
    | (at their current published version above) for registration, Google
    | account completion, and eligibility assessment alike. A document
    | published above but not listed here is informational only - it
    | never gates registration or eligibility.
    |
    */

    'required_documents' => ['terms', 'privacy'],

];
