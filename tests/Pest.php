<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function createAuthenticatedUser(array $attrs = []): \App\Models\User
{
    $user = \App\Models\User::factory()->create($attrs);
    \Laravel\Sanctum\Sanctum::actingAs($user);
    return $user;
}

function createUserWithBank(array $userAttrs = []): array
{
    $user = createAuthenticatedUser($userAttrs);
    $connection = \App\Models\BankConnection::factory()->create([
        'user_id' => $user->id,
        'status' => \App\Enums\ConnectionStatus::Active,
        'last_synced_at' => now(),
    ]);
    $account = \App\Models\BankAccount::factory()->create([
        'user_id' => $user->id,
        'bank_connection_id' => $connection->id,
    ]);
    return compact('user', 'connection', 'account');
}

function createUserWithBankAndProfile(array $userAttrs = []): array
{
    $data = createUserWithBank($userAttrs);
    $profile = \App\Models\UserFinancialProfile::factory()->create([
        'user_id' => $data['user']->id,
        'employment_type' => 'self_employed',
    ]);
    return array_merge($data, ['profile' => $profile]);
}
