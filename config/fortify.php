<?php

use Laravel\Fortify\Features;

return [

    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    // Where to redirect after login (not used for API/SPA)
    'home' => '/dashboard',

    'prefix' => '',

    'domain' => null,

    'middleware' => ['web'],

    'limiters' => [
        'login'          => 'login',
        'two-factor'     => 'two-factor',
        'verification'   => '6,1',
    ],

    'views' => false, // We handle views in the SPA, not Fortify

    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm'         => true,
            'confirmPassword' => true,
        ]),
    ],

];
