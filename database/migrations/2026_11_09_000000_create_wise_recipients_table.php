<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wise_recipients', function (Blueprint $table): void {
            $table->id();
            $table->morphs('payable');
            $table->string('profile_name', 100);
            $table->unsignedBigInteger('profile_id');
            $table->unsignedBigInteger('wise_recipient_id');
            $table->char('currency', 3);
            $table->string('type');
            $table->string('holder_name')->nullable();
            $table->string('summary')->nullable();
            $table->char('country', 2)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['profile_id', 'wise_recipient_id'], 'wise_recipients_profile_recipient_unique');
            $table->index(['payable_type', 'payable_id', 'currency'], 'wise_recipients_payable_currency_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wise_recipients');
    }
};
