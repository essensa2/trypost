<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('partner_key', 64)->default('ais');
            $table->uuid('external_project_id');
            $table->string('project_name');
            $table->string('locale', 10)->default('en');
            $table->char('api_token_id', 80)->nullable()->index();
            $table->timestamps();

            $table->unique(['partner_key', 'external_project_id']);
            $table->unique(['id', 'workspace_id']);
        });

        Schema::create('connection_authorization_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('partner_workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->uuid('external_request_id')->unique();
            $table->string('platform', 32);
            $table->char('token_digest', 64)->unique();
            $table->char('claim_digest', 64)->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignUuid('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('webhook_event_id')->nullable()->unique();
            $table->unsignedSmallInteger('webhook_attempts')->default(0);
            $table->timestamp('webhook_delivered_at')->nullable();
            $table->json('audit_log')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->foreign(['partner_workspace_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('partner_workspaces')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_authorization_requests');
        Schema::dropIfExists('partner_workspaces');
    }
};
