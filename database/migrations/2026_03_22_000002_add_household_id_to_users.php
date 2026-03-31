<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('household_id')->nullable()->constrained('households')->nullOnDelete();
            $table->string('household_role', 20)->default('member');

            $table->index('household_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['household_id']);
            $table->dropIndex(['household_id']);
            $table->dropColumn(['household_id', 'household_role']);
        });
    }
};
