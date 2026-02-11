<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Google OAuth
            $table->string('google_id')->nullable()->unique()->after('email');
            $table->string('avatar_url')->nullable()->after('google_id');

            // Fortify 2FA columns (if not using fortify:install migration)
            if (!Schema::hasColumn('users', 'two_factor_secret')) {
                $table->text('two_factor_secret')->nullable()->after('password');
                $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
                $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            }

            // Rate limiting / captcha audit
            $table->unsignedSmallInteger('failed_login_attempts')->default(0)->after('remember_token');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'google_id', 'avatar_url',
                'failed_login_attempts', 'locked_until',
            ]);
            // Don't drop 2FA columns if Fortify manages them
        });
    }
};
