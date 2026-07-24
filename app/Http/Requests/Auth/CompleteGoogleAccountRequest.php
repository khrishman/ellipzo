<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CompleteGoogleAccountRequest extends FormRequest
{
    /**
     * The legal documents that must be published before a Google-sourced
     * account can be created, mirroring RegisterUserRequest's gate for
     * the ordinary registration form.
     */
    private const REQUIRED_DOCUMENTS = ['terms', 'privacy'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Unchecked by default on the form; "accepted" already
            // requires the field to be present and truthy.
            'terms' => ['accepted'],
        ];
    }

    /**
     * Account creation cannot succeed while either required document is
     * still unpublished draft/placeholder content - this is a
     * system-state check, not a field-format check.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (self::REQUIRED_DOCUMENTS as $document) {
                if (! (bool) config("legal.documents.{$document}.published")) {
                    $validator->errors()->add(
                        'terms',
                        'Account creation is currently unavailable. Please try again later.',
                    );

                    return;
                }
            }
        });
    }
}
