<?php

use Illuminate\Support\Facades\Route;

Route::fallback(function () {
    $path = public_path('index.html');
    if (file_exists($path)) {
        return file_get_contents($path);
    }
    return 'React built files are missing in public/ folder. Please run npm run build-deploy in the store-web directory.';
});
