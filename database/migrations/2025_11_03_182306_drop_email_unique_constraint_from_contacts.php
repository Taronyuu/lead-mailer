<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasIndex('contacts', 'website_email_unique')) {
                $table->dropUnique('website_email_unique');
            }
            if (!Schema::hasIndex('contacts', 'domain_email_unique')) {
                $table->unique(['domain_id', 'email'], 'domain_email_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique('domain_email_unique');
            $table->unique('email', 'website_email_unique');
        });
    }
};
