<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** The signed-in user's own account. */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('admin.profile.edit', [
            'user' => $request->user()->load(['roles', 'branch', 'branches']),
            'permissions' => $request->user()->permissionNames(),
            'logins' => $request->user()->loginHistories()->latest()->limit(15)->get(),
            'tokens' => $request->user()->tokens()->latest()->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user->id)->whereNull('deleted_at')],
            'locale' => ['required', Rule::in(config('app.supported_locales', ['ar', 'en']))],
        ], [], [
            'name' => 'الاسم',
            'email' => 'البريد الإلكتروني',
            'phone' => 'رقم الهاتف',
            'locale' => 'لغة الواجهة',
        ]);

        $user->update($data);

        return back()->with('toast', ['type' => 'success', 'message' => 'تم تحديث الملف الشخصي.']);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'current_password.current_password' => 'كلمة المرور الحالية غير صحيحة.',
        ], [
            'current_password' => 'كلمة المرور الحالية',
            'password' => 'كلمة المرور الجديدة',
        ]);

        $request->user()->update(['password' => Hash::make($data['password'])]);

        return back()->with('toast', ['type' => 'success', 'message' => 'تم تغيير كلمة المرور.']);
    }

    /** Revoke a mobile app token the user no longer recognises. */
    public function revokeToken(Request $request, string $tokenId): RedirectResponse
    {
        $request->user()->tokens()->whereKey($tokenId)->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'تم إلغاء الجلسة.']);
    }
}
