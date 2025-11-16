<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->boolean('is_active')->default(false)->after('url');
        });

        DB::table('websites')
            ->where('status', 2)
            ->update(['is_active' => true]);

        Schema::table('websites', function (Blueprint $table) {
            if (Schema::hasColumn('websites', 'status')) {
                $table->dropIndex(['status', 'meets_requirements']);
                $table->dropIndex(['status']);
            }
        });

        Schema::table('websites', function (Blueprint $table) {
            if (Schema::hasColumn('websites', 'status')) {
                $table->dropColumn('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->unsignedTinyInteger('status')->default(0)->after('url')->index();
        });

        DB::table('websites')
            ->where('is_active', true)
            ->update(['status' => 2]);

        Schema::table('websites', function (Blueprint $table) {
            if (Schema::hasColumn('websites', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
