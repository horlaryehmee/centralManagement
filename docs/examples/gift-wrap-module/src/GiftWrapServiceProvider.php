<?php

namespace Modules\GiftWrap;

use Illuminate\Support\ServiceProvider;

class GiftWrapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GiftWrap::class);
    }
}
