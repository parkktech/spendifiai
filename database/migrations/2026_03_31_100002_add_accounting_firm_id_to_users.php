<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('accounting_firm_id')->nullable()->after('user_type');
            $table->foreign('accounting_firm_id')
                ->references('id')
                ->on('accounting_firms')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['accounting_firm_id']);
            $table->dropColumn('accounting_firm_id');
        });
    }
};
