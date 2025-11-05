<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->foreignId('default_email_template_id')
                ->nullable()
                ->after('smtp_credential_id')
                ->constrained('email_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropForeign(['default_email_template_id']);
            $table->dropColumn('default_email_template_id');
        });
    }
};
