<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parsed_emails', function (Blueprint $table) {
            $table->tinyInteger('retry_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();
            $table->string('search_source')->default('keyword'); // keyword, transaction_guided, sender
        });
    }

    public function down(): void
    {
        Schema::table('parsed_emails', function (Blueprint $table) {
            $table->dropColumn(['retry_count', 'last_retry_at', 'search_source']);
        });
    }
};
