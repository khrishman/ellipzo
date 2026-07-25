<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminOverviewController extends Controller
{
    /**
     * Minimal, honest overview: two real counts, nothing fabricated.
     * Every other admin section (Users, Campaigns, Finance, Risk, ...)
     * has no working page yet - this deliberately does not pretend
     * otherwise with placeholder metrics or fake activity.
     */
    public function show(): Response
    {
        $staffUserCount = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->distinct('model_id')
            ->count('model_id');

        return Inertia::render('admin/overview', [
            'totalUsers' => User::count(),
            'totalStaff' => $staffUserCount,
        ]);
    }
}
