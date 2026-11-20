<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central Update Management: clients (businesses), their installations
 * (independently hosted websites), releases (core / module / client
 * extension), deployments and their per-installation targets, and the
 * event log. No client website data is ever stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->uuid('key')->unique(); // shared with the client's installations; scopes extension releases
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->string('hosting_provider')->nullable();
            $table->text('hosting_notes')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active'); // active | suspended
            $table->timestamps();
        });

        Schema::create('installations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // public installation ID
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('domain');
            $table->string('environment', 20)->default('production'); // production | staging | test
            $table->boolean('is_test')->default(false); // eligible as the "test first" installation
            // pending (waiting to be connected) | active | suspended (no updates) | revoked (no access)
            $table->string('status', 20)->default('pending');
            $table->string('enrollment_code_hash')->nullable();
            $table->timestamp('enrollment_expires_at')->nullable();
            $table->text('secret')->nullable(); // encrypted; HMAC key shared with the installation
            $table->timestamp('secret_issued_at')->nullable();
            $table->string('core_version', 40)->nullable();
            $table->string('php_version', 40)->nullable();
            $table->string('framework_version', 40)->nullable();
            $table->json('server')->nullable();
            $table->unsignedSmallInteger('check_interval')->default(15); // minutes between heartbeats
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'last_seen_at']);
        });

        Schema::create('installation_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 100);
            $table->string('type', 20)->default('module'); // module | extension
            $table->string('name')->nullable();
            $table->string('version', 40);
            $table->boolean('enabled')->default(true);
            $table->json('requires')->nullable();
            $table->timestamps();
            $table->unique(['installation_id', 'slug']);
            $table->index('slug');
        });

        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 20); // core | module | extension
            $table->string('slug', 100); // "core" for core releases
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete(); // extensions only
            $table->string('version', 40);
            $table->string('name')->nullable();
            $table->text('notes')->nullable();
            $table->json('requires')->nullable(); // {core, php, modules: {slug: constraint}}
            $table->string('upgrade_from_min', 40)->nullable();
            $table->string('upgrade_from_max', 40)->nullable();
            $table->boolean('has_migrations')->default(false);
            $table->boolean('database_rollback')->default(false); // migrations can be rolled back
            $table->string('status', 20)->default('draft'); // draft | published | withdrawn
            $table->string('package_path')->nullable();
            $table->unsignedBigInteger('package_size')->nullable();
            $table->string('package_sha256', 64)->nullable();
            $table->unsignedInteger('file_count')->nullable();
            $table->longText('manifest')->nullable(); // signed JSON (exact bytes)
            $table->text('signature')->nullable(); // base64 Ed25519 signature of manifest
            $table->string('signing_key_id', 32)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'slug', 'status']);
        });

        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // scheduled | running | completed | completed_with_failures | failed | cancelled
            $table->string('status', 30)->default('scheduled');
            $table->timestamp('scheduled_at')->nullable();
            $table->foreignId('canary_installation_id')->nullable()->constrained('installations')->nullOnDelete();
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->boolean('allow_modified_files')->default(false);
            $table->json('selection')->nullable(); // how the targets were chosen (for the record)
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('deployment_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installation_id')->constrained()->cascadeOnDelete();
            // waiting_canary | queued | delivered | running | retry_wait | succeeded | failed | cancelled
            // | no_response | rollback_requested | rolling_back | rolled_back | rollback_failed
            $table->string('status', 30)->default('queued');
            $table->boolean('is_canary')->default(false);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('step', 40)->nullable();
            $table->string('from_version', 40)->nullable();
            $table->string('to_version', 40)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('last_report_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['deployment_id', 'installation_id']);
            $table->index(['installation_id', 'status']);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('deployment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('deployment_target_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('level', 10)->default('info'); // info | success | warning | error
            $table->string('type', 50);
            $table->string('message', 1000);
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['installation_id', 'id']);
            $table->index(['deployment_id', 'id']);
        });

        // Replay protection: each signed request's nonce may be used once.
        Schema::create('api_nonces', function (Blueprint $table) {
            $table->foreignId('installation_id')->constrained()->cascadeOnDelete();
            $table->string('nonce', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['installation_id', 'nonce']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        foreach (['api_nonces', 'events', 'deployment_targets', 'deployments', 'releases', 'installation_modules', 'installations', 'clients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
