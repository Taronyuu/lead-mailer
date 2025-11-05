<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->foreignId('smtp_credential_id')
                ->nullable()
                ->after('is_active')
                ->constrained('smtp_credentials')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropForeign(['smtp_credential_id']);
            $table->dropColumn('smtp_credential_id');
        });
    }
};
