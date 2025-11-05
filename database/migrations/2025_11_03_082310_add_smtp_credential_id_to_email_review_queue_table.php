<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('email_review_queue', function (Blueprint $table) {
            $table->foreignId('smtp_credential_id')->nullable()->after('email_template_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_review_queue', function (Blueprint $table) {
            $table->dropForeign(['smtp_credential_id']);
            $table->dropColumn('smtp_credential_id');
        });
    }
};
