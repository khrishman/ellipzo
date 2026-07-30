<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Wallet\Data\AdminLedgerFilters;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletAccountType;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Authorization is enforced entirely by the route's own
 * `permission:ledger.view` middleware, which runs before this request
 * class or the controller ever executes - authorize() only needs to
 * confirm that already happened, never re-derive it.
 *
 * Every filter is a plain scalar string: an array/object value fails the
 * `string` rule the same way an unknown enum value fails `in:`, both
 * producing Laravel's ordinary validation-redirect behavior. `cursor` is
 * deliberately not validated here - a malformed cursor gets its own
 * dedicated canonical redirect in the controller.
 *
 * `userId` and `username` are two distinct, mutually-exclusive fields
 * (`prohibits` each other) - deliberately never a single overloaded
 * "user" field with numeric-vs-username inference. UserProfile's own
 * username format (`^[A-Za-z0-9_]+$`) has no requirement that a username
 * contain a letter, so a purely numeric username is a real, possible
 * value - a single field guessing "looks numeric, must be an ID" would
 * silently and permanently make that real user's own transactions
 * unreachable by their own username. `username` additionally rejects any
 * value containing "@" - the one unambiguous signal of an email address -
 * so a full email address can never appear in this feature's query
 * string, redirect URL, or validation-error output.
 */
final class AdminLedgerFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Business references and correlation IDs are always stored
        // lowercase (PostLedgerTransactionCommand's own normalization) -
        // lowering here means a staff member can type either case and
        // still find the exact stored value.
        if ($this->has('businessReference') && is_string($this->input('businessReference'))) {
            $this->merge(['businessReference' => strtolower(trim($this->input('businessReference')))]);
        }

        if ($this->has('correlationId') && is_string($this->input('correlationId'))) {
            $this->merge(['correlationId' => strtolower(trim($this->input('correlationId')))]);
        }

        if ($this->has('id') && is_string($this->input('id'))) {
            $this->merge(['id' => strtolower(trim($this->input('id')))]);
        }

        if ($this->has('username') && is_string($this->input('username'))) {
            $this->merge(['username' => trim($this->input('username'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id' => ['sometimes', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! Str::isUlid($value)) {
                    $fail('The transaction ID must be a valid ULID.');
                }
            }],
            'type' => [
                'sometimes', 'nullable', 'string',
                Rule::in(array_map(fn (LedgerTransactionType $type) => $type->value, LedgerTransactionType::cases())),
            ],
            'accountType' => [
                'sometimes', 'nullable', 'string',
                Rule::in(array_map(fn (WalletAccountType $type) => $type->value, WalletAccountType::cases())),
            ],
            'userId' => [
                'sometimes', 'nullable', 'string', 'prohibits:username',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || preg_match('/^[1-9]\d*$/', $value) !== 1) {
                        $fail('The user ID filter must be an exact positive integer.');
                    }
                },
            ],
            'username' => [
                'sometimes', 'nullable', 'string', 'max:255', 'prohibits:userId',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        $fail('The username filter must be a string.');

                        return;
                    }

                    if (str_contains($value, '@')) {
                        $fail('The username filter must be an exact username, never an email address.');

                        return;
                    }

                    if (preg_match('/^[A-Za-z0-9_]{3,30}$/', $value) !== 1) {
                        $fail('The username filter must be an exact, validly-shaped username.');
                    }
                },
            ],
            'businessReference' => ['sometimes', 'nullable', 'string', 'max:191', 'regex:/^[a-z0-9:_-]+$/'],
            'correlationId' => ['sometimes', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || (! Str::isUuid($value) && ! Str::isUlid($value))) {
                    $fail('The correlation ID must be a valid UUID or ULID.');
                }
            }],
            'dateFrom' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'dateTo' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:dateFrom'],
        ];
    }

    /**
     * Laravel's own default validation-failure handling
     * (Illuminate\Foundation\Exceptions\Handler::invalid()) flashes the
     * entire raw request input to the session via withInput() before
     * redirecting - confirmed empirically, not assumed: submitting a
     * rejected email-shaped `username` value left it sitting in
     * session('_old_input') verbatim, which with SESSION_DRIVER=database
     * means a full email address would otherwise be written into the
     * sessions table.
     *
     * Mutating $this here is not enough: FormRequestServiceProvider builds
     * this object as a *copy* of the container's own bound request
     * (FormRequest::createFrom()) and never rebinds the container to point
     * at it (confirmed by reading that provider's source - no
     * `$app->instance('request', ...)` call exists there). The exception
     * handler later reads from the original container-bound instance, so
     * that is the object that must be stripped, not $this.
     */
    protected function failedValidation(Validator $validator): void
    {
        $bound = $this->container->make('request');
        $bound->query->remove('username');
        $bound->request->remove('username');

        parent::failedValidation($validator);
    }

    public function toFilters(): AdminLedgerFilters
    {
        $userId = $this->validated('userId');
        $dateFrom = $this->validated('dateFrom');
        $dateTo = $this->validated('dateTo');

        return new AdminLedgerFilters(
            transactionId: $this->validated('id'),
            transactionType: $this->validated('type') !== null ? LedgerTransactionType::from($this->validated('type')) : null,
            accountType: $this->validated('accountType') !== null ? WalletAccountType::from($this->validated('accountType')) : null,
            userId: $userId !== null ? (int) $userId : null,
            username: $this->validated('username'),
            businessReference: $this->validated('businessReference'),
            correlationId: $this->validated('correlationId'),
            dateFrom: $dateFrom !== null ? Carbon::parse($dateFrom, 'UTC')->startOfDay() : null,
            dateTo: $dateTo !== null ? Carbon::parse($dateTo, 'UTC')->endOfDay()->setMicrosecond(0) : null,
        );
    }
}
