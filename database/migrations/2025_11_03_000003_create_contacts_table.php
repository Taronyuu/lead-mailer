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
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->onDelete('cascade');
            $table->string('email', 255)->index();
            $table->string('name', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('position', 255)->nullable();
            $table->string('source_type', 50)->nullable()->index(); // contact_page, about_page, footer, header, body, team_page
            $table->string('source_url', 500)->nullable();
            $table->unsignedTinyInteger('priority')->default(50)->index();
            $table->boolean('is_validated')->default(false)->index();
            $table->boolean('is_valid')->default(false);
            $table->text('validation_error')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->boolean('contacted')->default(false)->index();
            $table->timestamp('first_contacted_at')->nullable()->index();
            $table->timestamp('last_contacted_at')->nullable();
            $table->unsignedInteger('contact_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['website_id', 'email'], 'website_email_unique');
            $table->index(['is_validated', 'is_valid', 'contacted']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
