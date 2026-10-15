<?php

namespace App\Http\Requests\Administration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserAccessRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
        if ($this->has('fund_id')) {
            $this->merge(['fund_ids' => $this->boolean('both_funds')
                ? $this->user()->fundingSources()->pluck('funding_sources.id')->all()
                : [$this->input('fund_id')]]);
        }
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('users.manage');
    }

    public function rules(): array
    {
        $create = $this->is('administration/users');

        return [
            'name' => [$create ? 'required' : 'sometimes', 'string', 'max:255'],
            'email' => [$create ? 'required' : 'sometimes', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => [$create ? 'required' : 'sometimes', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('is_active', true)],
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'fund_ids' => ['required', 'array', 'min:1'],
            'fund_ids.*' => ['required', 'integer', 'distinct', Rule::exists('funding_sources', 'id')->where('is_active', true)],
            'is_active' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
