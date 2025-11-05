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
        Schema::create('email_review_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->onDelete('cascade');
            $table->foreignId('contact_id')->constrained()->onDelete('cascade');
            $table->foreignId('email_template_id')->constrained()->onDelete('cascade');

            // Generated content
            $table->string('generated_subject');
            $table->text('generated_body');
            $table->text('generated_preheader')->nullable();

            // Review status
            $table->string('status', 20)->default('pending')->index(); // pending, approved, rejected
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            // Priority
            $table->unsignedTinyInteger('priority')->default(50)->index();

            $table->timestamps();

            // Indexes
            $table->index(['status', 'priority', 'created_at']);
            $table->index(['contact_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_review_queue');
    }
};
