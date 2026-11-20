<?php

use Extensions\AcmePay\Gateway;
use Illuminate\Support\Facades\Route;

Route::get('extensions/acme-pay/status', fn () => response()->json([
    'extension' => 'acme-pay',
    'version' => Gateway::VERSION,
    'gateway' => (new Gateway)->name(),
]));
