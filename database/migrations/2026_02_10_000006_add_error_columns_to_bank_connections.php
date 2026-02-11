<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_connections', function (Blueprint $table) {
            $table->string('error_code')->nullable()->after('sync_cursor');
            $table->text('error_message')->nullable()->after('error_code');
        });
    }

    public function down(): void
    {
        Schema::table('bank_connections', function (Blueprint $table) {
            $table->dropColumn(['error_code', 'error_message']);
        });
    }
};
