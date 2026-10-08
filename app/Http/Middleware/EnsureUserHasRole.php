<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batasi halaman menurut peran. Dipakai sebagai `role:admin` atau
 * `role:admin,teacher`.
 *
 * Pengguna yang membuka halaman bukan haknya tidak diberi halaman error
 * telanjang, tetapi dipulangkan ke halaman utamanya sendiri dengan keterangan
 * singkat supaya dia tahu kenapa.
 */
class EnsureUserHasRole
{
    /**
     * @param  array<int, string>  $roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($roles !== [] && ! in_array((string) $user->role, $roles, true)) {
            return redirect()
                ->route($user->homeRoute())
                ->withErrors(['role' => 'Halaman itu untuk '.$this->roleList($roles).'. Anda masuk sebagai '.$user->roleLabel().'.']);
        }

        return $next($request);
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function roleList(array $roles): string
    {
        $labels = array_map(fn (string $role): string => User::RoleLabels[$role] ?? $role, $roles);

        return implode(' atau ', $labels);
    }
}
