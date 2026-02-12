<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_connections', function (Blueprint $table) {
            $table->string('connection_type', 10)->default('oauth')->after('provider'); // oauth or imap
            $table->string('imap_host')->nullable()->after('connection_type');
            $table->integer('imap_port')->nullable()->after('imap_host');
            $table->string('imap_encryption', 10)->nullable()->after('imap_port'); // ssl or tls
        });
    }

    public function down(): void
    {
        Schema::table('email_connections', function (Blueprint $table) {
            $table->dropColumn(['connection_type', 'imap_host', 'imap_port', 'imap_encryption']);
        });
    }
};
