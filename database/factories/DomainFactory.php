<?php

use Faker\Generator as Faker;
use Keygen\Keygen;

$factory->define(App\Domain::class, function (Faker $faker) {
    return [
        'domain' => 'example.org'
    ];
});
