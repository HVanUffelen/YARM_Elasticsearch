<?php

namespace Yarm\Elasticsearch;

use Illuminate\Support\ServiceProvider;

class ElasticsearchServiceProvider extends ServiceProvider{

    public function boot()
    {

        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        //$this->loadViewsFrom(__DIR__.'/views','elasticsearch');
        //$this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->mergeConfigFrom(__DIR__ . '/config/elasticsearch.php','elasticsearch');
        $this->publishes([
            //__DIR__ . '/config/bookshelf.php' => config_path('bookshelf.php'),
            //__DIR__.'/views' => resource_path('views/vendor/bookshelf'),
            // Assets
            //__DIR__.'/js' => resource_path('js/vendor'),
        ],'elasticsearch');

        //after every update
        //run   php artisan vendor:publish [--provider="Yarm\Elasticsearch\ElasticsearchServiceProvider"][--tag="elasticsearch"]  --force
    }

    public function register()
    {

    }

}
