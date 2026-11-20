<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\GiftWrap\GiftWrap;

Route::get('modules/gift-wrap/status', fn (GiftWrap $wrap) => response()->json([
    'module' => 'gift-wrap',
    'version' => GiftWrap::VERSION,
    'price' => $wrap->price(),
    'table' => Schema::hasTable('gift_wrap_options'),
]));
