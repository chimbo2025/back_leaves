<?php

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
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
        ]);

        // An unauthenticated API call must produce a 401 JSON body, not a
        // redirect. Without this, Laravel falls back to route('login'), which
        // does not exist in this stateless API, and every unauthenticated
        // request from curl/Postman (Accept: */*) dies with a 500.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->expectsJson() ? null : '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Make the API surface uniformly JSON so the frontend never has to
        // parse an HTML error page out of a proxy response.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
