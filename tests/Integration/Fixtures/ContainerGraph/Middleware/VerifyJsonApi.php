<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Middleware;

use Closure;
use Illuminate\Http\Request;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\TokenVerifier;
use Symfony\Component\HttpFoundation\Response;

final class VerifyJsonApi
{
    public function handle(Request $request, Closure $next, TokenVerifier $verifier): Response
    {
        if (! $verifier->verify((string) $request->bearerToken())) {
            abort(401);
        }

        return $next($request);
    }
}
