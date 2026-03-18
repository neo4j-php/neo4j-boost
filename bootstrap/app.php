<?php

use App\Exceptions\Handler\ApiExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Enable sessions for API routes (needed for session-based auth)
        // StartSession and AddQueuedCookiesToResponse must be added for proper session cookie handling
        $middleware->appendToGroup('api', [
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        // Centralized API Exception Handler
        $exceptions->render(function (\Throwable $e, Request $request) {
            $handler = new ApiExceptionHandler;

            return $handler->handle($e, $request);
        });

        // Handle Vite manifest errors gracefully
        $exceptions->render(function (\Illuminate\Foundation\ViteManifestNotFoundException $e, $request) {
            $vitePort = env('VITE_PORT', '5173');
            $viteUrl = "http://localhost:{$vitePort}";
            $hotFile = public_path('hot');

            // Check if Vite is running
            $viteRunning = false;
            $connection = @fsockopen('localhost', (int) $vitePort, $errno, $errstr, 0.1);
            if ($connection) {
                fclose($connection);
                $viteRunning = true;
            }

            if ($viteRunning && app()->environment(['local', 'development', 'testing'])) {
                // Vite is running but hot file missing - create it and redirect
                if (! file_exists($hotFile)) {
                    @file_put_contents($hotFile, $viteUrl."\n");
                    @chmod($hotFile, 0644);
                }

                return redirect()->to($request->fullUrl());
            }

            // Show helpful error message
            $message = 'Vite assets are not available. ';
            if (app()->environment(['local', 'development', 'testing'])) {
                $message .= 'Please start the Vite dev server with: <code>npm run dev</code>';
            } else {
                $message .= 'Please build assets with: <code>npm run build</code>';
            }

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => strip_tags($message),
                    'help' => 'Start Vite: npm run dev OR Build assets: npm run build',
                ], 500);
            }

            return response('<html><body style="font-family: sans-serif; padding: 40px; max-width: 600px; margin: 0 auto;"><h1>Vite Assets Not Available</h1><p>'.$message.'</p><p><strong>Quick fix:</strong></p><ul><li>For development: Run <code>npm run dev</code> in your terminal</li><li>For production: Run <code>npm run build</code> to build assets</li></ul></body></html>', 500)
                ->header('Content-Type', 'text/html');
        });

        // Return JSON errors for API routes
        $exceptions->shouldRenderJsonWhen(function ($request, \Throwable $e) {
            return $request->is('api/*');
        });
    })->create();
