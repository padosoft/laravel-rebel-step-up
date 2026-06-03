<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rebel_step_up_challenges', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('tenant_id')->nullable();
            $table->string('subject_type');
            $table->string('subject_id');
            $table->string('guard')->nullable();
            $table->string('device_id')->nullable();

            $table->string('purpose');
            $table->string('required_assurance', 8);
            $table->boolean('require_phishing_resistant')->default(false);
            $table->string('achieved_assurance', 8)->nullable();
            $table->boolean('achieved_phishing_resistant')->nullable();
            $table->boolean('achieved_restricted')->nullable();
            $table->string('selected_driver');
            // riferimento opaco del driver (es. id challenge OTP)
            $table->string('driver_ref')->nullable();

            // PSD2/SCA dynamic linking: la conferma è legata a importo+beneficiario.
            $table->string('binding_hash', 128)->nullable();
            $table->decimal('bound_amount', 13, 2)->nullable();
            $table->string('bound_currency', 3)->nullable();
            $table->string('bound_payee')->nullable();
            $table->string('bound_order_ref')->nullable();
            $table->unsignedTinyInteger('key_version')->nullable();

            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('risk_score')->nullable();
            $table->json('risk_reasons')->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'purpose', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rebel_step_up_challenges');
    }
};
