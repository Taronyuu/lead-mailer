<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['status', 'last_checked_at', 'check_count', 'notes']);
            $table->dropSoftDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->unsignedTinyInteger('status')->default(0)->index();
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('check_count')->default(0);
            $table->text('notes')->nullable();
            $table->softDeletes();
        });
    }
};
