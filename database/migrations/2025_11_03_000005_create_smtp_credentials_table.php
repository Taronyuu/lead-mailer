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
        Schema::create('smtp_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('host', 255);
            $table->unsignedInteger('port')->default(587);
            $table->string('encryption', 10)->default('tls'); // tls or ssl
            $table->string('username', 255);
            $table->string('password', 255); // encrypted
            $table->string('from_address', 255);
            $table->string('from_name', 255);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('daily_limit')->default(10);
            $table->unsignedInteger('emails_sent_today')->default(0);
            $table->date('last_reset_date')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smtp_credentials');
    }
};
