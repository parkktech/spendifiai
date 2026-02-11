<?php

use App\Services\CaptchaService;
use Illuminate\Support\Facades\Http;

it('returns true when captcha is disabled', function () {
    config(['spendwise.captcha.enabled' => false]);

    $service = new CaptchaService();
    $result = $service->verify('any-token');

    expect($result)->toBeTrue();
});

it('returns true for score above threshold', function () {
    config([
        'spendwise.captcha.enabled' => true,
        'spendwise.captcha.secret_key' => 'test-secret',
        'spendwise.captcha.verify_url' => 'https://www.google.com/recaptcha/api/siteverify',
        'spendwise.captcha.threshold' => 0.5,
    ]);

    Http::fake([
        'www.google.com/recaptcha/api/siteverify' => Http::response([
            'success' => true,
            'score' => 0.9,
            'action' => 'login',
        ]),
    ]);

    $service = new CaptchaService();
    $result = $service->verify('token', 'login');

    expect($result)->toBeTrue();
});

it('returns false for score below threshold', function () {
    config([
        'spendwise.captcha.enabled' => true,
        'spendwise.captcha.secret_key' => 'test-secret',
        'spendwise.captcha.verify_url' => 'https://www.google.com/recaptcha/api/siteverify',
        'spendwise.captcha.threshold' => 0.5,
    ]);

    Http::fake([
        'www.google.com/recaptcha/api/siteverify' => Http::response([
            'success' => true,
            'score' => 0.2,
            'action' => 'login',
        ]),
    ]);

    $service = new CaptchaService();
    $result = $service->verify('token', 'login');

    expect($result)->toBeFalse();
});

it('returns false for action mismatch', function () {
    config([
        'spendwise.captcha.enabled' => true,
        'spendwise.captcha.secret_key' => 'test-secret',
        'spendwise.captcha.verify_url' => 'https://www.google.com/recaptcha/api/siteverify',
        'spendwise.captcha.threshold' => 0.5,
    ]);

    Http::fake([
        'www.google.com/recaptcha/api/siteverify' => Http::response([
            'success' => true,
            'score' => 0.9,
            'action' => 'register',
        ]),
    ]);

    $service = new CaptchaService();
    $result = $service->verify('token', 'login');

    expect($result)->toBeFalse();
});

it('returns false when API returns failure', function () {
    config([
        'spendwise.captcha.enabled' => true,
        'spendwise.captcha.secret_key' => 'test-secret',
        'spendwise.captcha.verify_url' => 'https://www.google.com/recaptcha/api/siteverify',
        'spendwise.captcha.threshold' => 0.5,
    ]);

    Http::fake([
        'www.google.com/recaptcha/api/siteverify' => Http::response([
            'success' => false,
            'error-codes' => ['invalid-input-response'],
        ]),
    ]);

    $service = new CaptchaService();
    $result = $service->verify('bad-token');

    expect($result)->toBeFalse();
});

it('returns false on API exception', function () {
    config([
        'spendwise.captcha.enabled' => true,
        'spendwise.captcha.secret_key' => 'test-secret',
        'spendwise.captcha.verify_url' => 'https://www.google.com/recaptcha/api/siteverify',
        'spendwise.captcha.threshold' => 0.5,
    ]);

    Http::fake(fn () => throw new \RuntimeException('Connection failed'));

    $service = new CaptchaService();
    $result = $service->verify('token');

    expect($result)->toBeFalse();
});
