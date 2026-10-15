<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\UserAccessRequest;
use App\Models\Department;
use App\Models\FundingSource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    private function authorizeActor(Request $request): User
    {
        abort_unless($request->user()?->can('users.manage'), 403);

        return $request->user();
    }

    public function index(Request $request)
    {
        $actor = $this->authorizeActor($request);
        $fundIds = $actor->fundingSources()->pluck('funding_sources.id');
        $funds = $actor->fundingSources()->get()->map(fn ($f) => ['id' => $f->id, 'label' => $f->name]);
        $fields = [
            ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true],
            ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'required' => true],
            ['name' => 'password', 'label' => 'Initial password', 'type' => 'password', 'required' => true, 'description' => '12+ characters, with upper and lower case, a number and a symbol.'],
            ['name' => 'department_id', 'label' => 'Department', 'type' => 'select', 'required' => true, 'options' => Department::where('is_active', true)->get()->map(fn ($d) => ['value' => (string) $d->id, 'label' => $d->name])],
            ['name' => 'role', 'label' => 'Role', 'type' => 'select', 'required' => true, 'options' => Role::where('guard_name', 'web')->get()->map(fn ($r) => ['value' => $r->name, 'label' => $r->name])],
            ['name' => 'fund_id', 'label' => 'Initial funding source', 'type' => 'select', 'required' => true, 'options' => $funds->map(fn ($f) => ['value' => (string) $f['id'], 'label' => $f['label']])],
            ['name' => 'reason', 'label' => 'Reason for access', 'type' => 'textarea', 'required' => true],
        ];
        $users = User::with('roles', 'fundingSources', 'department')
            ->whereHas('fundingSources', fn ($q) => $q->whereIn('funding_sources.id', $fundIds))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%')->orWhere('email', 'like', '%'.$request->string('q').'%')))
            ->orderBy('name')->paginate(20)->withQueryString();
        $rows = $users->getCollection()->filter(fn ($u) => $u->fundingSources->pluck('id')->diff($fundIds)->isEmpty())->map(function ($user) use ($fields) {
            $accessFields = array_values(array_filter($fields, fn ($f) => ! in_array($f['name'], ['name', 'email', 'password', 'fund_id'])));
            $accessFields[] = ['name' => 'fund_id', 'label' => 'Funding source', 'type' => 'select', 'required' => true, 'options' => $fields[5]['options']];
            $accessFields[] = ['name' => 'both_funds', 'label' => 'Grant all my assigned funds', 'type' => 'checkbox'];
            $accessFields[] = ['name' => 'is_active', 'label' => 'Account active', 'type' => 'checkbox'];

            return [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'role' => $user->roles->pluck('name')->join(', '), 'department' => $user->department?->name,
                'funds' => $user->fundingSources->pluck('name')->join(', '), 'status' => $user->is_active ? 'active' : 'inactive',
                'actions' => [
                    ['label' => 'Manage access', 'form' => ['title' => 'Manage '.$user->name, 'action' => '/administration/users/'.$user->id.'/access', 'fields' => $accessFields, 'defaults' => ['role' => $user->roles->first()?->name, 'department_id' => (string) $user->department_id, 'fund_id' => (string) $user->fundingSources->first()?->id, 'both_funds' => $user->fundingSources->count() > 1, 'is_active' => $user->is_active]]],
                    ['label' => 'Revoke sessions', 'href' => '/administration/users/'.$user->id.'/sessions', 'method' => 'post', 'confirm' => 'End all current sessions for this user?'],
                ],
            ];
        })->values();

        return Inertia::render('stocknest', [
            'page' => ['key' => 'administration', 'title' => 'People & access', 'subtitle' => 'Manage accounts, roles and funding source access.', 'columns' => collect(['name' => 'Name', 'email' => 'Email', 'role' => 'Role', 'department' => 'Department', 'funds' => 'Funding sources', 'status' => 'Status'])->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'format' => $key === 'status' ? 'status' : 'text'])->values(), 'rows' => $rows, 'pagination' => ['current_page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'total' => $users->total(), 'prev_page_url' => $users->previousPageUrl(), 'next_page_url' => $users->nextPageUrl()]],
            'funds' => $funds, 'filters' => $request->only('q'),
            'createForm' => ['title' => 'Create account', 'action' => '/administration/users', 'fields' => $fields],
            'pageActions' => $actor->can('roles.manage') && FundingSource::pluck('id')->diff($actor->fundingSources()->pluck('funding_sources.id'))->isEmpty() ? [['label' => 'Role permissions', 'form' => ['title' => 'Configure a role', 'action' => '/administration/roles', 'fields' => [
                ['name' => 'name', 'label' => 'Role name', 'type' => 'text', 'required' => true],
                ['name' => 'permissions', 'label' => 'Permission names, separated by commas', 'type' => 'textarea', 'required' => true, 'description' => $actor->getAllPermissions()->pluck('name')->join(', ')],
                ['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true],
            ]]]] : [],
        ]);
    }

    private function accessData(UserAccessRequest $request): array
    {
        // Form metadata uses a simple select; server still validates the final array.
        return $request->validated();
    }

    public function store(UserAccessRequest $request)
    {
        $data = $this->accessData($request);
        $this->checkGrants($request->user(), $data);
        DB::transaction(function () use ($data, $request) {
            $user = User::create(collect($data)->only('name', 'email', 'password')->all());
            $user->forceFill(['department_id' => $data['department_id'], 'is_active' => true, 'email_verified_at' => now()])->save();
            $user->assignRole($data['role']);
            $user->fundingSources()->sync($data['fund_ids']);
            $this->audit($request->user(), $user, 'user.created', [], $data['reason']);
        });

        return back()->with('success', 'Account created. Share the initial password securely and ask the user to change it.');
    }

    public function access(UserAccessRequest $request, User $user)
    {
        $data = $this->accessData($request);
        $actor = $request->user();
        $this->checkTarget($actor, $user);
        $this->checkGrants($actor, $data);
        if ($actor->id === $user->id) {
            throw ValidationException::withMessages(['workflow' => 'Ask another administrator to change your own access.']);
        }
        DB::transaction(function () use ($actor, $user, $data) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($user);
            $user->forceFill(['department_id' => $data['department_id'], 'is_active' => $data['is_active'] ?? true, 'remember_token' => Str::random(60)])->save();
            $user->syncRoles([$data['role']]);
            $user->fundingSources()->sync($data['fund_ids']);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $this->audit($actor, $user->fresh(), 'user.access_changed', $before, $data['reason']);
        });

        return back()->with('success', 'Access updated and existing sessions ended.');
    }

    public function revokeSessions(Request $request, User $user)
    {
        $actor = $this->authorizeActor($request);
        $this->checkTarget($actor, $user);
        DB::transaction(function () use ($actor, $user) {
            $user->forceFill(['remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $this->audit($actor, $user, 'user.sessions_revoked', [], 'Administrator revoked active sessions');
        });

        return back()->with('success', 'Sessions ended.');
    }

    public function role(Request $request)
    {
        $actor = $this->authorizeActor($request);
        // Roles are global. A fund-limited administrator may not change a role
        // that also controls users outside their own funding source scope.
        abort_unless($actor->can('roles.manage'), 403);
        abort_unless(FundingSource::pluck('id')->diff($actor->fundingSources()->pluck('funding_sources.id'))->isEmpty(), 403);
        $data = $request->validate(['name' => 'required|string|max:100', 'permissions' => 'required|string|max:2000', 'reason' => 'required|string|min:5|max:2000']);
        $permissions = array_values(array_filter(array_map('trim', explode(',', $data['permissions']))));
        abort_unless(collect($permissions)->diff($actor->getAllPermissions()->pluck('name'))->isEmpty(), 403);
        // Prevent changing a role assigned to the acting administrator.
        abort_if($actor->hasRole($data['name']), 422, 'Another administrator must change your role.');
        DB::transaction(function () use ($actor, $data, $permissions) {
            $role = Role::findOrCreate($data['name'], 'web');
            $role = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            $before = $role->permissions->pluck('name')->all();
            $role->syncPermissions($permissions);
            DB::table('audit_logs')->insert(['user_id' => $actor->id, 'action' => 'role.permissions_changed', 'subject_type' => 'role', 'subject_id' => $role->id, 'before_values' => json_encode($before), 'after_values' => json_encode($permissions), 'reason' => $data['reason'], 'created_at' => now()]);
        });

        return back()->with('success', 'Role permissions saved.');
    }

    private function checkGrants(User $actor, array $data): void
    {
        abort_unless(collect($data['fund_ids'])->diff($actor->fundingSources()->pluck('funding_sources.id'))->isEmpty(), 403);
        $role = Role::findByName($data['role'], 'web');
        abort_unless($role->permissions->pluck('name')->diff($actor->getAllPermissions()->pluck('name'))->isEmpty(), 403);
    }

    private function checkTarget(User $actor, User $target): void
    {
        abort_unless($target->fundingSources()->pluck('funding_sources.id')->diff($actor->fundingSources()->pluck('funding_sources.id'))->isEmpty(), 403);
    }

    private function snapshot(User $user): array
    {
        return ['email' => $user->email, 'is_active' => $user->is_active, 'department_id' => $user->department_id, 'roles' => $user->roles->pluck('name')->all(), 'fund_ids' => $user->fundingSources()->pluck('funding_sources.id')->all()];
    }

    private function audit(User $actor, User $user, string $action, array $before, string $reason): void
    {
        DB::table('audit_logs')->insert(['user_id' => $actor->id, 'action' => $action, 'subject_type' => 'user', 'subject_id' => $user->id, 'before_values' => json_encode($before), 'after_values' => json_encode($this->snapshot($user)), 'reason' => $reason, 'created_at' => now()]);
    }
}
