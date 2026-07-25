<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the email address before validation runs, so the
     * uniqueness check and the stored value always agree.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email') && is_string($this->input('email'))) {
            $this->merge([
                'email' => mb_strtolower(trim($this->input('email'))),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            // Unchecked by default on the form; "accepted" already requires
            // the field to be present and truthy, so a missing or false
            // value fails validation without a separate "required" rule.
            'terms' => ['accepted'],
        ];
    }

    /**
     * Registration includes accepting the current version of the required
     * legal documents, so it cannot succeed while any of them are still
     * unpublished draft/placeholder content - that would record a real
     * user as having "accepted" text nobody has actually reviewed. This
     * is a system-state check, not a field-format check, so it runs as an
     * additional pass over the whole request rather than a per-field rule.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (config('legal.required_documents') as $document) {
                if (! (bool) config("legal.documents.{$document}.published")) {
                    $validator->errors()->add(
                        'terms',
                        'Registration is currently unavailable. Please try again later.',
                    );

                    return;
                }
            }
        });
    }
}
