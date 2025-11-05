<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->foreignId('website_id')
                ->nullable()
                ->after('domain')
                ->constrained('websites')
                ->nullOnDelete();

            $table->boolean('is_active')->default(false)->after('website_id');
            $table->string('detected_platform', 50)->nullable()->after('is_active');
            $table->unsignedInteger('page_count')->default(0)->after('detected_platform');
            $table->unsignedInteger('word_count')->default(0)->after('page_count');
            $table->text('content_snapshot')->nullable()->after('word_count');
            $table->boolean('meets_requirements')->default(false)->after('content_snapshot');
            $table->json('requirement_match_details')->nullable()->after('meets_requirements');
            $table->timestamp('crawled_at')->nullable()->after('requirement_match_details');
            $table->timestamp('crawl_started_at')->nullable()->after('crawled_at');
            $table->unsignedInteger('crawl_attempts')->default(0)->after('crawl_started_at');
            $table->text('crawl_error')->nullable()->after('crawl_attempts');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('domain_id')
                ->nullable()
                ->after('website_id')
                ->constrained('domains')
                ->cascadeOnDelete();
        });

        DB::table('contacts')->update(['domain_id' => DB::raw('website_id')]);

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['website_id']);
            $table->dropColumn('website_id');
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn([
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
        Schema::table('websites', function (Blueprint $table) {
            $table->boolean('is_active')->default(false)->after('url');
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

        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('website_id')
                ->nullable()
                ->after('id')
                ->constrained('websites')
                ->cascadeOnDelete();
        });

        DB::table('contacts')->update(['website_id' => DB::raw('domain_id')]);

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['domain_id']);
            $table->dropColumn('domain_id');
        });

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
};
