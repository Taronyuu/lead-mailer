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
        Schema::create('domain_website', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();

            $table->boolean('matches')->default(false)->index();
            $table->json('match_details')->nullable();

            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedInteger('word_count')->default(0);
            $table->string('detected_platform', 50)->nullable();
            $table->longText('html_snapshot')->nullable();

            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            $table->unique(['domain_id', 'website_id']);
            $table->index(['website_id', 'matches', 'evaluated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domain_website');
    }
};
