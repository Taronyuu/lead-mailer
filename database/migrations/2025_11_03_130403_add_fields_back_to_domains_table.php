<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->unsignedTinyInteger('status')->default(0)->index()->after('domain');
            $table->timestamp('last_checked_at')->nullable()->after('status');
            $table->unsignedInteger('check_count')->default(0)->after('last_checked_at');
            $table->text('notes')->nullable()->after('check_count');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['status', 'last_checked_at', 'check_count', 'notes']);
            $table->dropSoftDeletes();
        });
    }
};
