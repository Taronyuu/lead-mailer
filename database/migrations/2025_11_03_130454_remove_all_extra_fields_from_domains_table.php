<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            if (Schema::hasIndex('domains', 'domains_status_index')) {
                $table->dropIndex(['status']);
            }
        });

        Schema::table('domains', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('domains', 'status')) {
                $columns[] = 'status';
            }
            if (Schema::hasColumn('domains', 'last_checked_at')) {
                $columns[] = 'last_checked_at';
            }
            if (Schema::hasColumn('domains', 'check_count')) {
                $columns[] = 'check_count';
            }
            if (Schema::hasColumn('domains', 'notes')) {
                $columns[] = 'notes';
            }
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
            if (Schema::hasColumn('domains', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
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
