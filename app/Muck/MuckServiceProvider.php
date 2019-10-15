<?php

namespace App\Providers;

use App\Contracts\MuckConnection;
use App\Muck\FakeMuckConnection;
use App\Muck\HttpMuckConnection;
use Illuminate\Support\ServiceProvider;


class MuckServiceProvider extends ServiceProvider
{
    protected $defer = true;

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(MuckConnection::class, function($app) {
            $config = $this->app['config']['muck'];
            $driver = $config['driver'];
            if ($driver == 'fake') return new FakeMuckConnection($config);
            if ($driver == 'http') return new HttpMuckConnection($config);
            throw new \Exception('Unrecognized muck driver: ' . $driver);
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [MuckConnection::class];
    }
}
