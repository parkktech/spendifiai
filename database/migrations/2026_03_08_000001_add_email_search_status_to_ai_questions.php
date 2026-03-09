<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_questions', function (Blueprint $table) {
            $table->string('email_search_status', 20)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_questions', function (Blueprint $table) {
            $table->dropColumn('email_search_status');
        });
    }
};
