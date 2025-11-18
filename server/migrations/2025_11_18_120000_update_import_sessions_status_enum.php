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
     * Updates the import_sessions status enum to support enhanced workflow states.
     * This migration extends the base table created in 2025_11_10_110660_create_import_sessions_table.php
     * to support file uploading, validation results, and ready states.
     */
    public function up(): void
    {
        // Update the status enum to include all required values
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to the original enum values
        // First, update any records with new status values back to compatible ones
        DB::statement("UPDATE import_sessions SET status = 'created' WHERE status IN ('uploading', 'ready', 'has_errors', 'has_warnings')");
        
        // Then modify the enum back to original
        DB::statement("ALTER TABLE import_sessions MODIFY COLUMN status ENUM(
            'created',
            'validating', 
            'mapping',
            'processing',
            'completed',
            'failed',
            'cancelled'
        ) NOT NULL DEFAULT 'created'");
    }
};