<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRoleRequest extends FormRequest
{
    /**
     * The route itself is gated by the staff.manage permission
     * (routes/admin.php) - this request only validates field shape and
     * server-controlled reference data, never who is allowed to submit it.
     */
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
            'email' => ['required', 'string', 'email', 'exists:users,email'],
            // Null/omitted means "remove staff access" (no role). When
            // present, it must be one of the roles that actually exist in
            // the database right now - never a hardcoded string list -
            // so a role the seeder never created cannot be assigned.
            'role' => ['nullable', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
