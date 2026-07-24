<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class LegalController extends Controller
{
    /**
     * Show a legal document's status page.
     *
     * The slug is only ever used as an allowlist lookup key into
     * config('legal.documents') - never interpolated into a file path,
     * view name, or query, so there is no path-traversal surface here
     * regardless of what the client sends. An unknown slug 404s.
     */
    public function show(string $document): Response
    {
        $config = config("legal.documents.{$document}");

        if ($config === null) {
            abort(404);
        }

        return Inertia::render('legal/show', [
            'document' => [
                'slug' => $document,
                'title' => $config['title'],
                'version' => $config['version'],
                'published' => (bool) $config['published'],
            ],
        ]);
    }
}
