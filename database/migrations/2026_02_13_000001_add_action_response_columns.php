<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_recommendations', function (Blueprint $table) {
            $table->string('response_type')->nullable()->after('status');
            $table->text('response_data')->nullable()->after('response_type');
            $table->decimal('actual_monthly_savings', 10, 2)->nullable()->after('response_data');
            $table->text('ai_alternatives')->nullable()->after('actual_monthly_savings');
            $table->timestamp('alternatives_generated_at')->nullable()->after('ai_alternatives');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('response_type')->nullable()->after('status');
            $table->decimal('previous_amount', 10, 2)->nullable()->after('response_type');
            $table->text('response_reason')->nullable()->after('previous_amount');
            $table->text('ai_alternatives')->nullable()->after('response_reason');
            $table->timestamp('responded_at')->nullable()->after('ai_alternatives');
            $table->timestamp('alternatives_generated_at')->nullable()->after('responded_at');
        });

        Schema::create('savings_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('source_type'); // recommendation, subscription
            $table->unsignedBigInteger('source_id');
            $table->string('action_taken'); // cancelled, reduced
            $table->decimal('monthly_savings', 10, 2);
            $table->decimal('previous_amount', 10, 2)->nullable();
            $table->decimal('new_amount', 10, 2)->nullable();
            $table->string('status')->default('claimed'); // claimed, verified
            $table->string('month', 7); // "2026-02"
            $table->text('notes')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'month']);
            $table->index(['user_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_ledger');
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['response_type', 'previous_amount', 'response_reason', 'ai_alternatives', 'responded_at', 'alternatives_generated_at']);
        });
        Schema::table('savings_recommendations', function (Blueprint $table) {
            $table->dropColumn(['response_type', 'response_data', 'actual_monthly_savings', 'ai_alternatives', 'alternatives_generated_at']);
        });
    }
};
