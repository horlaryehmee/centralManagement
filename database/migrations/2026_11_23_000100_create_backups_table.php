<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website backups requested from central. The website builds the backup (database,
 * optionally uploaded files) and uploads it in resumable chunks; central stores it
 * encrypted at rest. See docs/ARCHITECTURE.md → Backups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained()->cascadeOnDelete();
            // requested → delivered → uploading → completed | failed; requested and never picked up → expired
            $table->string('status', 20)->default('requested');
            $table->boolean('include_files')->default(true);
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->char('sha256', 64)->nullable();
            $table->string('path')->nullable();
            $table->boolean('encrypted')->default(false);
            // What the website says is inside: database, file count, its version.
            $table->json('contents')->nullable();
            $table->string('error', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['installation_id', 'status']);
        });

        Schema::table('installations', function (Blueprint $table) {
            // Features the website's agent reports it supports, e.g. ["backup"].
            $table->json('capabilities')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
        Schema::table('installations', function (Blueprint $table) {
            $table->dropColumn('capabilities');
        });
    }
};
