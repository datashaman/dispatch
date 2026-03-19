<?php

use App\Providers\AppServiceProvider;
use App\Providers\EventSourceServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    EventSourceServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
];
