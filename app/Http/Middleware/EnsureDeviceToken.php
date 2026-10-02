<?php

namespace App\Http\Middleware;

use App\Services\DeviceToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeviceToken
{
    public function __construct(private DeviceToken $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->tokens->matches((string) $request->route('token')), 404);

        return $next($request);
    }
}
