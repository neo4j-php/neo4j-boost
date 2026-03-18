<?php

use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withExceptions(function () {
        // Use framework default exception handler for console.
    })
    ->create();
