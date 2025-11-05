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
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->text('subject_template');
            $table->longText('body_template');
            $table->text('preheader')->nullable();
            $table->boolean('ai_enabled')->default(false);
            $table->text('ai_instructions')->nullable();
            $table->string('ai_tone', 50)->nullable();
            $table->unsignedInteger('ai_max_tokens')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('usage_count')->default(0);
            $table->json('available_variables')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
