<?php

namespace App\Providers;

use App\Notifications\Channels\FcmChannel;
use App\Services\DuitkuService;
use App\Services\GooglePlacesService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DuitkuService::class, fn () => new DuitkuService(
            merchantCode: (string) config('services.duitku.merchant_code'),
            apiKey: (string) config('services.duitku.api_key'),
            sandbox: (bool) config('services.duitku.sandbox'),
        ));

        $this->app->singleton(GooglePlacesService::class, fn () => new GooglePlacesService(
            apiKey: (string) config('services.google_places.key'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Notification::extend('fcm', fn ($app) => $app->make(FcmChannel::class));
    }
}
