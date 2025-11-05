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
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->onDelete('cascade');
            $table->string('url', 500)->unique();
            $table->unsignedTinyInteger('status')->default(0)->index(); // 0=pending, 1=crawling, 2=completed, 3=failed, 4=per_review
            $table->string('title', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('detected_platform', 50)->nullable()->index();
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedInteger('word_count')->default(0);
            $table->longText('content_snapshot')->nullable();
            $table->boolean('meets_requirements')->default(false)->index();
            $table->json('requirement_match_details')->nullable();
            $table->timestamp('crawled_at')->nullable();
            $table->timestamp('crawl_started_at')->nullable();
            $table->unsignedTinyInteger('crawl_attempts')->default(0);
            $table->text('crawl_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'meets_requirements']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
