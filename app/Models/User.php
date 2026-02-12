<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'avatar_url',
        'email_verified_at',
        'failed_login_attempts',
        'locked_until',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'google_id',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'locked_until' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes' => 'encrypted:array',  // Auto encrypt + JSON encode/decode
            'two_factor_secret' => 'encrypted',
        ];
    }

    // ─── LedgerIQ Relationships ───

    public function bankConnections(): HasMany
    {
        return $this->hasMany(BankConnection::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function aiQuestions(): HasMany
    {
        return $this->hasMany(AIQuestion::class);
    }

    public function emailConnections(): HasMany
    {
        return $this->hasMany(EmailConnection::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function savingsRecommendations(): HasMany
    {
        return $this->hasMany(SavingsRecommendation::class);
    }

    public function savingsTarget(): HasOne
    {
        return $this->hasOne(SavingsTarget::class)->latestOfMany();
    }

    public function budgetGoals(): HasMany
    {
        return $this->hasMany(BudgetGoal::class);
    }

    public function financialProfile(): HasOne
    {
        return $this->hasOne(UserFinancialProfile::class);
    }

    public function savingsProgress(): HasMany
    {
        return $this->hasMany(SavingsProgress::class);
    }

    // ─── Helpers ───

    public function hasBankConnected(): bool
    {
        return $this->bankConnections()->where('status', 'active')->exists();
    }

    public function hasProfileComplete(): bool
    {
        return $this->financialProfile()
            ->whereNotNull('employment_type')
            ->exists();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return ! is_null($this->two_factor_secret) && ! is_null($this->two_factor_confirmed_at);
    }

    public function isGoogleUser(): bool
    {
        return ! is_null($this->google_id);
    }
}
