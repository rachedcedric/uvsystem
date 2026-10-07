<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('tenant_subscription_id')->constrained('tenant_subscriptions')->restrictOnDelete();
            $table->foreignId('requested_plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->string('requested_cycle', 20);
            $table->string('status', 20)->index();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('quote')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_signups', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('email');
            $table->string('email_normalized')->index();
            $table->string('phone_normalized', 30)->nullable()->index();
            $table->string('ip', 45)->nullable()->index();
            $table->string('plan_slug', 100);
            $table->string('cycle', 20);
            $table->text('payload');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_signups');
        Schema::dropIfExists('subscription_change_requests');
    }
};
