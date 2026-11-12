<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 04A — election draft details. Three additive, nullable columns on `elections`; no existing column,
 * table or constraint changes, and no data is rewritten.
 *
 *  - `description`  : optional free-text description entered with the basic information.
 *  - `template_key` : the starting template chosen at creation (NULL = "Customize from scratch"). Recorded for
 *                     provenance only; it is distinct from `type` (what kind of election this is). It is NOT
 *                     configuration: no template contents are defined or applied yet, and `config_json` stays the
 *                     frozen snapshot artifact.
 *  - `creation_key` : the per-form submission key (UUID). UNIQUE, so the database itself guarantees that one
 *                     submitted form creates at most one election, across retries, double clicks, concurrent
 *                     requests and cache loss. NULL for elections created any other way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('elections', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->string('template_key', 100)->nullable()->after('type');
            $table->uuid('creation_key')->nullable()->unique()->after('template_key');
        });
    }

    public function down(): void
    {
        Schema::table('elections', function (Blueprint $table) {
            $table->dropUnique(['creation_key']);
            $table->dropColumn(['description', 'template_key', 'creation_key']);
        });
    }
};
