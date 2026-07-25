<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\EligibilityAssessor;
use App\Support\MissingRequirement;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly EligibilityAssessor $eligibilityAssessor,
    ) {}

    /**
     * Eligibility is computed only here, on-demand for the one page that
     * currently needs it - never shared globally on every Inertia
     * request. Props expose only machine-readable codes and already
     * safe/user-facing document slugs, never raw model data.
     */
    public function show(Request $request): Response
    {
        $result = $this->eligibilityAssessor->assess($request->user());

        return Inertia::render('dashboard/index', [
            'eligibility' => [
                'status' => $result->status->value,
                'missingRequirements' => array_map(
                    fn (MissingRequirement $requirement): array => [
                        'code' => $requirement->code->value,
                        'context' => $requirement->context,
                    ],
                    $result->missingRequirements,
                ),
            ],
        ]);
    }
}
