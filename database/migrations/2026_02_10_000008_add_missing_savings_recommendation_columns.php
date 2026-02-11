<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_recommendations', function (Blueprint $table) {
            $table->text('action_steps')->nullable()->after('impact');
            $table->text('related_merchants')->nullable()->after('action_steps');
        });
    }

    public function down(): void
    {
        Schema::table('savings_recommendations', function (Blueprint $table) {
            $table->dropColumn(['action_steps', 'related_merchants']);
        });
    }
};
