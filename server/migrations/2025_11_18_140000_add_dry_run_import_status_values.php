<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds missing dry run and import status values to the import_sessions status enum.
     * This fixes SQLSTATE[01000]: Warning: 1265 Data truncated for column 'status' at row 1
     * when setting status to 'processing_dry_run', 'dry_run_completed', or 'importing'.
     * 
     * Missing status values identified from OrderImportController and related services:
     * - processing_dry_run: Used during dry run execution
     * - dry_run_completed: Used when dry run finishes successfully
     * - importing: Used during actual order import execution
     */
    public function up(): void
    {
        // Update the status enum to include dry run and import status values
        DB::statement("ALTER TABLE import_sessions MODIFY COLUMN status ENUM(
            'created',
            'uploading',
            'validating', 
            'mapping',
            'processing',
            'processing_dry_run',
            'dry_run_completed',
            'importing',
            'completed',
            'failed',
            'cancelled',
            'ready',
            'has_errors',
            'has_warnings'
        ) NOT NULL DEFAULT 'created'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Update any records using the new status values to fallback states
        DB::statement("UPDATE import_sessions SET status = 'processing' WHERE status IN ('processing_dry_run', 'importing')");
        DB::statement("UPDATE import_sessions SET status = 'ready' WHERE status = 'dry_run_completed'");
        
        // Revert enum to previous state without dry run status values
        DB::statement("ALTER TABLE import_sessions MODIFY COLUMN status ENUM(
            'created',
            'uploading',
            'validating', 
            'mapping',
            'processing',
            'completed',
            'failed',
            'cancelled',
            'ready',
            'has_errors',
            'has_warnings'
        ) NOT NULL DEFAULT 'created'");
    }
};