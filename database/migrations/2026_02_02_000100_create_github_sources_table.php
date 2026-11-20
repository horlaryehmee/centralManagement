<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub repositories releases are pulled from (core app, a module, or one
 * client's extension). Each GitHub release's zip asset becomes a release here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_sources', function (Blueprint $table) {
            $table->id();
            $table->string('repository'); // owner/name
            $table->text('token')->nullable(); // encrypted; needed for private repositories
            $table->string('type', 20)->default('core'); // core | module | extension
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete(); // extensions
            $table->string('asset_pattern')->default('*.zip');
            $table->boolean('include_prereleases')->default(false);
            $table->boolean('auto_import')->default(true);
            $table->boolean('auto_publish')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::table('releases', function (Blueprint $table) {
            $table->foreignId('github_source_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
            $table->unsignedBigInteger('github_release_id')->nullable()->after('github_source_id');
            $table->string('github_url')->nullable()->after('github_release_id');
        });
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('github_source_id');
            $table->dropColumn(['github_release_id', 'github_url']);
        });
        Schema::dropIfExists('github_sources');
    }
};
