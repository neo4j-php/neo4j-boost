<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTokenIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
