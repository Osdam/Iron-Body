<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// El pago ePayco es 100% in-app vía API (routes/api.php). No hay checkout web.
