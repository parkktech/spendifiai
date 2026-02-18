<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_financial_profiles', function (Blueprint $table) {
            $table->string('housing_status', 20)->nullable()->after('has_home_office');
        });
    }

    public function down(): void
    {
        Schema::table('user_financial_profiles', function (Blueprint $table) {
            $table->dropColumn('housing_status');
        });
    }
};
