<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordRequest;
use App\Http\Requests\ProfileRequest;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $sessions = [];
        if (config('session.driver') === 'database') {
            $sessions = DB::table(config('session.table', 'sessions'))->where('user_id', $request->user()->id)
                ->orderByDesc('last_activity')->limit(10)->get()->map(fn ($s) => [
                    'ip' => $s->ip_address,
                    'agent' => mb_substr((string) $s->user_agent, 0, 80),
                    'last_active' => date('c', $s->last_activity),
                    'current' => $s->id === $request->session()->getId(),
                ])->all();
        }

        return Inertia::render('Profile/Edit', [
            'profile' => $request->user()->only('name', 'email', 'phone') + ['avatar_url' => $request->user()->avatar_url],
            'sessions' => $sessions,
        ]);
    }

    public function update(ProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->safe()->only(['name', 'email', 'phone']));

        if ($request->hasFile('avatar')) {
            // Stored under a generated name on the public disk; only validated images reach this point.
            $new = $request->file('avatar')->store('avatars', 'public');
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            $user->avatar_path = $new;
        }
        $user->save();
        ActivityLog::record('profile.updated', $user);

        return back()->with('success', 'Profile updated.');
    }

    public function password(PasswordRequest $request): RedirectResponse
    {
        $request->user()->update(['password' => $request->input('password')]);
        ActivityLog::record('profile.password_changed', $request->user());

        return back()->with('success', 'Password changed.');
    }

    /** Sign out every other browser/device. Requires the current password. */
    public function logoutOthers(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        Auth::logoutOtherDevices($request->input('password'));
        ActivityLog::record('profile.logout_other_devices', $request->user());

        return back()->with('success', 'Other sessions have been signed out.');
    }
}
