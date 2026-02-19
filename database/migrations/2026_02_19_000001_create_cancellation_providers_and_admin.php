<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_providers', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('slug')->unique();
            $table->json('aliases');
            $table->string('cancellation_url', 500)->nullable();
            $table->string('cancellation_phone', 50)->nullable();
            $table->text('cancellation_instructions')->nullable();
            $table->string('difficulty')->default('easy'); // easy, medium, hard
            $table->string('category', 100)->nullable();
            $table->boolean('is_essential')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->index('category');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('cancellation_provider_id')->nullable()->after('is_essential')
                ->constrained('cancellation_providers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancellation_provider_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });

        Schema::dropIfExists('cancellation_providers');
    }
};
