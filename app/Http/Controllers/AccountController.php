<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Halaman akun: identitas pemilik akun dan jalan untuk mengganti kata sandi
 * sendiri. Halaman ini juga pintu masuk bagi pengguna yang kata sandinya masih
 * bawaan tetapi sedang membuka halaman ganti kata sandi.
 */
class AccountController extends Controller
{
    public function index(Request $request): View
    {
        return view('account.index', [
            'user' => $request->user(),
            'classes' => SchoolClass::query()->orderedByName()->pluck('name'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $rules = User::memberRules($user);
        unset($rules['role']);
        $rules['phone_number'] = ['nullable', 'string', 'max:20'];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return to_route('account.index')
                ->withErrors($validator)
                ->withInput();
        }

        $user->update(User::applyStudyFields([
            ...$validator->validated(),
            'role' => $user->role,
        ]));

        return to_route('account.index')->with('success', 'Identitas akun berhasil diperbarui.');
    }
}
