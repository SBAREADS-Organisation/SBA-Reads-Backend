<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        // $userWithRoles = User::with(['roles'])->find($user->id);

        if (!$user) {
            return response()->json([
                'data' => null,
                'code' => 401,
                'message' => 'Unauthorized',
                'error' => 'Unauthorized'
            ], 401);
        }

        //TODO - Use the User model's roles relationship to check users access

        if (method_exists($user, 'load')) {
            $user->load('roles');
        }

        // dd($user->roles, $user->getRoleNames()->first(), $roles);

        // Check each role; allow if user has ANY of them
        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                return $next($request);
            }
        }

        // dd($user->account_type, $roles);

        // Assuming 'role' is a column in your 'users' table
        if (!in_array($user->account_type, $roles)) {
            return response()->json([
                'data' => null,
                'code' => 403,
                'message' => 'Forbidden',
                'error' => 'Forbidden. You do not have the required access right.',
                // 'required_roles' => $roles,
                // 'your_role' => $user->getRoleNames(),
            ], 403);
        }

        return $next($request);
    }
}
