<?php

declare(strict_types=1);

namespace App\Http\Requests\Wallet;

use App\Domain\Wallet\Data\TransactionHistoryFilters;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletAccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ownership is enforced by the controller/query service, which only ever
 * acts on $request->user()'s own accounts - there is no user identifier
 * anywhere in this request for a policy to check.
 *
 * `account`/`type` are validated as plain scalar strings against a fixed
 * allowlist: an array, an object, or an unknown string all fail the
 * `string`/`in:` rules together and receive Laravel's own normal
 * validation-redirect behavior (302 back with errors), never a 422 on an
 * ordinary browser GET request. `cursor` is deliberately not validated
 * here - a malformed cursor gets its own dedicated canonical redirect in
 * the controller, not a validation-error redirect.
 */
final class TransactionHistoryFilterRequest extends FormRequest
{
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
            'account' => [
                'sometimes', 'nullable', 'string',
                Rule::in(array_map(fn (WalletAccountType $type) => $type->value, TransactionHistoryFilters::allowedAccountTypes())),
            ],
            'type' => [
                'sometimes', 'nullable', 'string',
                Rule::in(array_map(fn (LedgerTransactionType $type) => $type->value, LedgerTransactionType::cases())),
            ],
        ];
    }

    public function accountTypeFilter(): ?WalletAccountType
    {
        $value = $this->validated('account');

        return $value !== null ? WalletAccountType::from($value) : null;
    }

    public function transactionTypeFilter(): ?LedgerTransactionType
    {
        $value = $this->validated('type');

        return $value !== null ? LedgerTransactionType::from($value) : null;
    }
}
