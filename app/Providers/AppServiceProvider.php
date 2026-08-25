<?php

namespace App\Providers;

use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;

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
        /*
         * Every sign-in screen shows the other language beside the current
         * one, so it is shared with all of them here rather than threaded
         * through four controllers — the one that forgot would be the screen
         * that broke.
         */
        View::composer('auth.*', function (ViewInstance $view) {
            $view->with('other', SetLocale::other());
        });
    }
}
