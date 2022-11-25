<?php

namespace Aramics\MpesaSdk;

use Illuminate\Support\ServiceProvider;

/**
 * Class MpesaServiceProvider
 */
class MpesaServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('mpesaSdk', Mpesa::class);
    }
}