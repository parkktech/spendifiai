<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cookie_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visitor_id', 64);
            $table->string('consent_version', 10);
            $table->string('region', 10);
            $table->boolean('necessary')->default(true);
            $table->boolean('analytics')->default(false);
            $table->boolean('marketing')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('action', 20);
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('visitor_id');
            $table->index('created_at');
        });

        // Partial index for efficient user consent lookups
        DB::statement('CREATE INDEX idx_consent_user_latest ON cookie_consents (user_id, created_at DESC) WHERE user_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('cookie_consents');
    }
};
