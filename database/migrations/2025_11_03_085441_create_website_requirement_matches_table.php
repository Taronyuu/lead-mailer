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
        Schema::create('website_requirement_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_requirement_id')->constrained()->cascadeOnDelete();
            $table->boolean('matches')->default(false);
            $table->text('match_details')->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'website_requirement_id'], 'website_requirement_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('website_requirement_matches');
    }
};
