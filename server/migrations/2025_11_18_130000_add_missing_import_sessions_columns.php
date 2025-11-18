<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds missing columns to import_sessions table required by controller implementation.
     * This migration completes the import system by adding file management, enhanced tracking,
     * and additional timestamp/counter columns discovered during controller development.
     */
    public function up(): void
    {
        Schema::table('import_sessions', function (Blueprint $table) {
            // File-related columns
            $table->string('file_path')->nullable()->after('file_uuid');
            $table->string('file_name')->nullable()->after('file_path');
            $table->string('file_type')->nullable()->after('file_name');
            
            // Field mapping configuration
            $table->json('field_mappings')->nullable()->after('meta');
            
            // Additional timestamp tracking
            $table->timestamp('parsed_at')->nullable()->after('started_at');
            $table->timestamp('dry_run_completed_at')->nullable()->after('parsed_at');
            $table->timestamp('import_started_at')->nullable()->after('dry_run_completed_at');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            
            // Additional row counting columns
            $table->integer('imported_rows')->default(0)->after('failed_rows');
            $table->integer('valid_rows')->default(0)->after('imported_rows');
            $table->integer('warning_rows')->default(0)->after('valid_rows');
            $table->integer('duplicate_rows')->default(0)->after('warning_rows');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'file_path',
                'file_name', 
                'file_type',
                'field_mappings',
                'parsed_at',
                'dry_run_completed_at', 
                'import_started_at',
                'cancelled_at',
                'imported_rows',
                'valid_rows',
                'warning_rows',
                'duplicate_rows'
            ]);
        });
    }
};