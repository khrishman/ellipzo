<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\UserProfile;
use Closure;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Ownership is enforced by the controller, which only ever acts on
     * $request->user()->profile() - there is no user identifier anywhere
     * in this request for a policy to check.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim the username (case is preserved) and normalize the country
     * code to uppercase before validation runs. `username_normalized` is
     * never read from the request - it is never sent by the client and
     * is derived independently below and by the model's own mutator.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('username') && is_string($this->input('username'))) {
            $trimmed = trim($this->input('username'));
            $this->merge(['username' => $trimmed === '' ? null : $trimmed]);
        }

        if ($this->has('country_code') && is_string($this->input('country_code'))) {
            $this->merge(['country_code' => mb_strtoupper(trim($this->input('country_code')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => [
                'nullable', 'string', 'min:3', 'max:30', 'regex:/^[A-Za-z0-9_]+$/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $normalized = UserProfile::normalizeUsername(is_string($value) ? $value : null);

                    if ($normalized === null) {
                        return;
                    }

                    $taken = UserProfile::where('username_normalized', $normalized)
                        ->where('user_id', '!=', $this->user()->id)
                        ->exists();

                    if ($taken) {
                        $fail('This username is already taken.');
                    }
                },
            ],
            // Strict Y-m-d only (matches the native <input type="date"> format);
            // plausibility bounds only, no age-eligibility rule here.
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:1900-01-01'],
            // Uppercase two-letter format check only. This does not validate
            // against a canonical list of real, assigned ISO 3166-1 codes -
            // that belongs to the later country-capability task, which will
            // own the actual `countries` table.
            'country_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            // A simple language[-REGION] shape check covering Ellipzo's
            // currently supported locale subset - not complete BCP-47
            // validation (no scripts, variants, or extension subtags).
            'locale' => ['nullable', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[A-Z]{2})?$/'],
            'timezone' => ['nullable', 'string', 'max:64', Rule::in(DateTimeZone::listIdentifiers())],
        ];
    }
}
