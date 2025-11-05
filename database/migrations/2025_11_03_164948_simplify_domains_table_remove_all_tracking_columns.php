<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropForeign(['website_id']);
            $table->dropColumn([
                'website_id',
                'is_active',
                'detected_platform',
                'page_count',
                'word_count',
                'content_snapshot',
                'meets_requirements',
                'requirement_match_details',
                'crawled_at',
                'crawl_started_at',
                'crawl_attempts',
                'crawl_error',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(false);
            $table->string('detected_platform', 50)->nullable();
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedInteger('word_count')->default(0);
            $table->text('content_snapshot')->nullable();
            $table->boolean('meets_requirements')->default(false);
            $table->json('requirement_match_details')->nullable();
            $table->timestamp('crawled_at')->nullable();
            $table->timestamp('crawl_started_at')->nullable();
            $table->unsignedInteger('crawl_attempts')->default(0);
            $table->text('crawl_error')->nullable();
        });
    }
};
