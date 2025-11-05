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
        Schema::create('email_sent_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('smtp_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('email_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_email', 255)->index();
            $table->string('recipient_name', 255)->nullable();
            $table->string('subject', 500);
            $table->longText('body');
            $table->string('status', 20)->default('sent')->index(); // sent, failed, bounced
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->index();
            $table->timestamps();

            $table->index(['contact_id', 'sent_at']);
            $table->index(['website_id', 'sent_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_sent_log');
    }
};
