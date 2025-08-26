<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class InterventionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register('Intervention\Image\ImageServiceProvider');
    }

    public function boot(): void
    {
        $this->app->alias('Image', 'Intervention\Image\Facades\Image');
    }
}