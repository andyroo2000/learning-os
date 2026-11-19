<?php

namespace App\Providers;

use App\Support\Storage\PrivateGoogleCloudFilesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

final class PrivateMediaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('gcs-private', fn ($app, array $config) => $app->make(PrivateGoogleCloudFilesystem::class)->make($config));
    }
}
