<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropIndex(['tld']);
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('tld');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('tld', 10)->nullable()->index()->after('domain');
        });
    }
};
