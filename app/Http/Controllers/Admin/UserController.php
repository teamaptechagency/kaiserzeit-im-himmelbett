<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Accounts — staff and guests in one list, told apart by role.
 *
 * Three rules run through everything here, and all three exist because of the
 * same failure: a site nobody can get into.
 *
 *   1. Only an administrator may change roles. An editor who could promote
 *      themselves is an administrator with extra steps.
 *   2. The last active administrator cannot be deleted, deactivated or
 *      demoted.
 *   3. Nobody can deactivate or delete their own account.
 */
class UserController extends Controller
{
    public function index(Request $request)
    {
        $role   = $request->query('role', 'staff');
        $search = trim((string) $request->query('q', ''));

        $users = User::query()
            ->when($role === 'staff', fn ($q) => $q->staff())
            ->when($role === 'guests', fn ($q) => $q->guests())
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->withCount('bookings')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.users.index', [
            'users'  => $users,
            'role'   => $role,
            'search' => $search,
            'tally'  => [
                'staff'  => User::staff()->count(),
                'guests' => User::guests()->count(),
            ],
        ]);
    }

    public function create()
    {
        return view('admin.users.form', ['user' => new User(['role' => User::ROLE_EDITOR, 'is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $user = new User();
        $user->fill([
            'name'      => $data['name'],
            'username'  => $data['username'],
            'email'     => $data['email'],
            'phone'     => $data['phone'] ?? null,
            'role'      => $this->roleFor($request, $data['role'] ?? User::ROLE_GUEST),
            'whatsapp'  => $data['whatsapp'] ?? null,
            'locale'    => $data['locale'] ?? 'de',
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        $user->password = Hash::make($data['password']);
        $user->save();

        ActivityLogger::log('created', $user, "Created the account {$user->email}");

        return redirect(admin_url('/users'))->with('status', 'Account created.');
    }

    public function edit(User $user)
    {
        return view('admin.users.form', [
            'user'     => $user,
            'activity' => ActivityLog::where('user_id', $user->id)->latest('created_at')->limit(20)->get(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);

        $role = $this->roleFor($request, $data['role'] ?? $user->role, $user);
        $active = (bool) ($data['is_active'] ?? false);

        /* Rules 2 and 3: never leave the site without an administrator, and
           never let anyone shut themselves out. */
        if ($user->id === $request->user()->id && ! $active) {
            return back()->withErrors(['is_active' => 'You cannot deactivate your own account.'])->withInput();
        }

        if ($this->wouldStrandTheSite($user, $role, $active)) {
            return back()->withErrors(['role' => 'This is the last active administrator.'])->withInput();
        }

        $user->fill([
            'name'      => $data['name'],
            'username'  => $data['username'],
            'email'     => $data['email'],
            'phone'     => $data['phone'] ?? null,
            'role'      => $role,
            'whatsapp'  => $data['whatsapp'] ?? null,
            'locale'    => $data['locale'] ?? 'de',
            'is_active' => $active,
        ]);

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        ActivityLogger::saved($user, 'updated', "Updated the account {$user->email}");

        return redirect(admin_url('/users'))->with('status', 'Account updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'You cannot delete your own account.']);
        }

        if ($this->wouldStrandTheSite($user, null, false)) {
            return back()->withErrors(['user' => 'This is the last active administrator.']);
        }

        $label = $user->email;
        ActivityLogger::log('deleted', $user, "Deleted the account {$label}");
        $user->delete();

        return redirect(admin_url('/users'))->with('status', "Deleted {$label}.");
    }

    /* -------------------------------------------------------------- pieces */

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            /* Required here too. An account made in this panel without one
               could not sign in by username, and nobody would find that out
               until the person tried. */
            'username'  => [
                'required', 'string', 'min:3', 'max:30',
                'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]{2,29}$/',
                Rule::unique('users')->ignore($user?->id),
            ],
            'email'     => ['required', 'email', 'max:190', Rule::unique('users')->ignore($user?->id)],
            'password'  => [$user ? 'nullable' : 'required', 'string', 'min:10', 'confirmed'],
            'role'      => ['nullable', Rule::in(array_keys(User::ROLES))],
            'phone'     => ['nullable', 'string', 'max:40'],
            'whatsapp'  => ['nullable', 'string', 'max:40'],
            'locale'    => ['nullable', Rule::in(array_keys(config('kaiserzeit.locales')))],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'password.min'    => 'Use at least 10 characters. Longer beats clever.',
            'username.regex'  => 'Letters, numbers, . _ and - only, at least 3 characters.',
            'username.unique' => 'That username is taken.',
        ]);
    }

    /** Rule 1: only an administrator may set roles. */
    private function roleFor(Request $request, string $wanted, ?User $existing = null): string
    {
        if ($request->user()->isAdmin()) {
            return $wanted;
        }

        return $existing?->role ?? User::ROLE_GUEST;
    }

    /** Would this change remove the last way into the panel? */
    private function wouldStrandTheSite(User $user, ?string $newRole, bool $stillActive): bool
    {
        if (! $user->isAdmin() || ! $user->is_active) {
            return false;
        }

        $staysAdmin = $newRole === User::ROLE_ADMIN && $stillActive;

        if ($staysAdmin) {
            return false;
        }

        return User::where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->doesntExist();
    }
}
