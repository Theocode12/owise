<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wise_authorizations', function (Blueprint $table): void {
            // Owise models one Wise user authorization shared by all configured profiles (id 1).
            $table->id();
            $table->text('email');
            $table->text('registration_code');
            $table->string('language', 2)->default('EN');
            $table->unsignedBigInteger('wise_user_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->text('scope')->nullable();
            $table->string('status', 24);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wise_authorizations');
    }
};
