<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wise_transfers', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('payable');
            $table->unsignedBigInteger('recipient_id')->nullable()->index();
            $table->unsignedBigInteger('wise_recipient_id')->nullable();
            $table->string('account')->nullable();
            $table->unsignedBigInteger('wise_connection_id')->nullable();
            $table->unsignedBigInteger('profile_id');
            $table->uuid('customer_transaction_id')->unique();
            $table->string('idempotency_key')->nullable();
            $table->uuid('wise_quote_id')->nullable();
            $table->unsignedBigInteger('wise_transfer_id')->nullable()->unique();
            $table->string('status', 32)->index();
            $table->string('wise_status', 64)->nullable()->index();
            $table->string('funding_mode', 16);
            $table->string('funding_status', 32)->nullable();
            $table->timestamp('funding_attempted_at')->nullable();
            $table->timestamp('funded_at')->nullable();
            $table->unsignedBigInteger('balance_transaction_id')->nullable();
            $table->char('source_currency', 3);
            $table->bigInteger('source_amount')->nullable();
            $table->char('target_currency', 3);
            $table->bigInteger('target_amount')->nullable();
            $table->bigInteger('fee_amount')->nullable();
            $table->decimal('rate', 18, 8)->nullable();
            $table->string('reference', 255)->nullable();
            $table->timestamp('wise_status_occurred_at', 3)->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            // Database-enforced idempotency per payable.
            $table->unique(['payable_type', 'payable_id', 'idempotency_key'], 'wise_transfers_payable_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wise_transfers');
    }
};
