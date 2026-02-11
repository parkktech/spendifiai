<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── Monthly Savings Targets ───
        Schema::create('savings_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->decimal('monthly_target', 10, 2);
            $table->string('motivation')->nullable();          // "Emergency fund", "Vacation", "Debt payoff"
            $table->date('target_start_date');
            $table->date('target_end_date')->nullable();       // null = ongoing
            $table->decimal('goal_total', 12, 2)->nullable();  // e.g. $10,000 emergency fund
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });

        // ─── AI-Generated Action Plans (concrete steps to hit target) ───
        Schema::create('savings_plan_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('savings_target_id')->constrained()->onDelete('cascade');

            // What to do
            $table->string('title');
            $table->text('description');
            $table->text('how_to');                            // Step-by-step instructions

            // Numbers
            $table->decimal('monthly_savings', 10, 2);         // How much this action saves
            $table->decimal('current_spending', 10, 2);        // What they spend now
            $table->decimal('recommended_spending', 10, 2);    // What the AI suggests
            $table->string('category');                         // Which spending category

            // Metadata
            $table->string('difficulty');                        // easy, medium, hard
            $table->string('impact');                           // high, medium, low
            $table->integer('priority');                         // 1 = cut first, ascending
            $table->boolean('is_essential_cut')->default(false); // true = cutting into essentials (warn user)

            // Related data
            $table->json('related_merchants')->nullable();
            $table->json('related_subscription_ids')->nullable();

            // User interaction
            $table->string('status')->default('suggested');     // suggested, accepted, rejected, completed
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['savings_target_id', 'status']);
            $table->index(['savings_target_id', 'priority']);
        });

        // ─── Monthly Savings Progress Tracking ───
        Schema::create('savings_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('savings_target_id')->constrained()->onDelete('cascade');
            $table->string('month', 7);                         // "2026-01", "2026-02"
            $table->decimal('income', 12, 2)->default(0);
            $table->decimal('total_spending', 12, 2)->default(0);
            $table->decimal('actual_savings', 12, 2)->default(0); // income - spending
            $table->decimal('target_savings', 10, 2);
            $table->decimal('gap', 10, 2)->default(0);           // target - actual (positive = behind)
            $table->decimal('cumulative_saved', 12, 2)->default(0);
            $table->decimal('cumulative_target', 12, 2)->default(0);
            $table->boolean('target_met')->default(false);
            $table->json('category_breakdown')->nullable();      // Spending per category that month
            $table->json('plan_adherence')->nullable();          // Which plan actions were followed
            $table->timestamps();

            $table->unique(['user_id', 'savings_target_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_progress');
        Schema::dropIfExists('savings_plan_actions');
        Schema::dropIfExists('savings_targets');
    }
};
