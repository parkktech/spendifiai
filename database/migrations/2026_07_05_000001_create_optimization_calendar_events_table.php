<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration: optimization_calendar_events (MON-02 / D15 / D16).
 *
 * Stores predictive calendar entries for the ChangeMonitor's calendar-watcher
 * side: bonus lead-time alerts, year-end purchase items, and income-shift
 * persistence anchors.
 *
 * IMPORTANT: metadata stores periods/types ONLY — never money values (T-14-09-03).
 * No cent figures, no tax estimates — those live in OptimizationFinding.
 *
 * Forward-only and additive: never drops or modifies existing columns.
 *
 * Index on [user_id, tax_year]: primary access pattern for ChangeMonitor per-user sweep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('optimization_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('tax_year');
            // event_type: 'bonus' | 'year_end_purchase' | 'income_shift_detected' | ...
            $table->string('event_type', 50);
            // When the event is expected to occur (e.g. bonus payroll cutoff)
            $table->timestamp('expected_at')->nullable();
            // Alert lead time: fire alert when now >= expected_at - lead_time_days
            $table->unsignedSmallInteger('lead_time_days')->default(21);
            // Set when the alert has been fired (dedupe: never re-fire once set)
            $table->timestamp('alert_fired_at')->nullable();
            // Periods/types only — NO money values (T-14-09-03)
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Primary access pattern: per-user per-year sweep in ChangeMonitor
            $table->index(['user_id', 'tax_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optimization_calendar_events');
    }
};
