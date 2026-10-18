<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        if ($user->must_change_password && Hash::check($input['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a different password from the initial password.']);
        }

        $user->forceFill([
            'password' => $input['password'],
            'must_change_password' => false,
            'remember_token' => Str::random(60),
        ])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
