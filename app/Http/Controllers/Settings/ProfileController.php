<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateProfileRequest;
use App\Models\UserProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the authenticated user's own profile settings.
     *
     * Deliberately reads only $request->user() - there is no user
     * identifier in the route, so there is nothing for this action to
     * misuse even if it tried.
     */
    public function edit(Request $request): Response
    {
        $profile = $request->user()->profile;

        return Inertia::render('settings/profile', [
            'profile' => [
                'username' => $profile?->username,
                'dateOfBirth' => $profile?->date_of_birth?->toDateString(),
                'countryCode' => $profile?->country_code,
                'locale' => $profile?->locale,
                'timezone' => $profile?->timezone,
            ],
        ]);
    }

    /**
     * Update (or lazily create, on first save) the authenticated user's
     * own profile row.
     *
     * The Form Request's uniqueness check happens before this write and
     * closes the common case, but it cannot close the race where another
     * request claims the same normalized username in between. That race
     * is closed here, at the point of persistence, using the database's
     * own unique index as the final authority.
     */
    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        try {
            $request->user()->profile()->updateOrCreate([], $request->validated());
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->resolveUniqueConstraintViolation($request, $exception);
        }

        return back()->with('status', 'profile-updated');
    }

    /**
     * A unique-constraint violation at persistence time only means "this
     * request lost a race" if it was actually caused by the normalized
     * username colliding with a different user - confirmed here by
     * re-querying with the server-derived canonical value, never by
     * inspecting the database driver's error message text (which differs
     * between MariaDB and SQLite). When confirmed, it is reported as an
     * ordinary username validation error. Any other unique-constraint
     * violation is not this method's to explain, so it is returned
     * unchanged for the caller to rethrow.
     */
    private function resolveUniqueConstraintViolation(
        UpdateProfileRequest $request,
        UniqueConstraintViolationException $exception,
    ): ValidationException|UniqueConstraintViolationException {
        $rawUsername = $request->validated('username');

        $normalized = UserProfile::normalizeUsername(is_string($rawUsername) ? $rawUsername : null);

        if ($normalized === null) {
            return $exception;
        }

        $takenByAnotherUser = UserProfile::where('username_normalized', $normalized)
            ->where('user_id', '!=', $request->user()->id)
            ->exists();

        if (! $takenByAnotherUser) {
            return $exception;
        }

        return ValidationException::withMessages([
            'username' => ['This username is already taken.'],
        ]);
    }
}
