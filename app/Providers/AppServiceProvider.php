<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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
        // On Vercel, /var/task is read-only. Redirect writable paths to /tmp.
        if (isset($_ENV['VERCEL']) || isset($_SERVER['VERCEL'])) {
            $tmpStorage = '/tmp/laravel';

            // Create required directories
            foreach ([
                $tmpStorage,
                $tmpStorage . '/framework',
                $tmpStorage . '/framework/cache',
                $tmpStorage . '/framework/sessions',
                $tmpStorage . '/framework/views',
                $tmpStorage . '/logs',
            ] as $dir) {
                if (! is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
            }

            $this->app->useStoragePath($tmpStorage);
            config([
                'view.compiled'  => $tmpStorage . '/framework/views',
                'cache.stores.file.path' => $tmpStorage . '/framework/cache',
                'session.files'  => $tmpStorage . '/framework/sessions',
                'logging.channels.single.path' => $tmpStorage . '/logs/laravel.log',
            ]);
        }
    }
}
