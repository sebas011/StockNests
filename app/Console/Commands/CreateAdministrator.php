<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\FundingSource;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdministrator extends Command
{
    protected $signature = 'stocknest:admin {--email=} {--name=}';

    protected $description = 'Create an initial administrator with an interactively entered password';

    public function handle(): int
    {
        $data = [
            'email' => $this->option('email') ?: $this->ask('Administrator email'),
            'name' => $this->option('name') ?: $this->ask('Administrator name'),
            'password' => $this->secret('Password (at least 12 characters, mixed case, number and symbol)'),
        ];
        $data['email'] = mb_strtolower(trim((string) $data['email']));
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($data) {
            $user = User::create($data);
            $user->forceFill(['email_verified_at' => now(), 'is_active' => true, 'department_id' => Department::query()->value('id')])->save();
            $user->assignRole('Super Administrator');
            $user->fundingSources()->sync(FundingSource::query()->pluck('id'));
            DB::table('audit_logs')->insert([
                'user_id' => $user->id, 'action' => 'administrator.created',
                'subject_type' => 'user', 'subject_id' => $user->id,
                'after_values' => json_encode(['email' => $user->email, 'role' => 'Super Administrator'], JSON_THROW_ON_ERROR),
                'reason' => 'Interactive administrator bootstrap', 'created_at' => now(),
            ]);
        });
        $this->info('Administrator created. No password was logged.');

        return self::SUCCESS;
    }
}
