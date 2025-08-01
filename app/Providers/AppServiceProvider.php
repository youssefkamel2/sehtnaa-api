<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register Socialite providers
        $socialite = $this->app->make('Laravel\Socialite\Contracts\Factory');

        $socialite->extend('facebook', function ($app) use ($socialite) {
            $config = $app['config']['services.facebook'];
            return $socialite->buildProvider(
                \SocialiteProviders\Facebook\Provider::class,
                $config
            );
        });

        $socialite->extend('google', function ($app) use ($socialite) {
            $config = $app['config']['services.google'];
            return $socialite->buildProvider(
                \SocialiteProviders\Google\Provider::class,
                $config
            );
        });
    }
}
