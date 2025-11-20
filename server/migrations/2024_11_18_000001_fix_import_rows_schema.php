<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('import_rows', function (Blueprint $table) {
            // Add company_uuid for multi-tenancy (CRITICAL)
            $table->uuid('company_uuid')->after('public_id')->nullable()->index();
            
            // Add deleted_at for SoftDeletes trait (CRITICAL)
            $table->timestamp('deleted_at')->nullable()->after('updated_at')->index();
            
            // Add processing workflow columns
            $table->string('processing_status')->after('status')->default('pending');
            $table->text('processing_message')->nullable()->after('processing_status');
            
            // Add resolution workflow columns
            $table->enum('resolution_status', ['pending', 'auto_fixed', 'manual_fixed', 'ignored', 'cannot_fix'])
                  ->default('pending')->after('processing_message');
            $table->string('resolution_method')->nullable()->after('resolution_status');
            
            // Add additional error tracking columns
            $table->string('error_type')->nullable()->after('resolution_method');
            $table->enum('severity', ['info', 'warning', 'error', 'critical'])
                  ->nullable()->after('error_type');
                  
            // Add validation tracking columns (separate from general errors/warnings)
            $table->json('validation_errors')->nullable()->after('warnings');
            $table->json('validation_warnings')->nullable()->after('validation_errors');
            
            // Add suggestions column
            $table->json('suggestions')->nullable()->after('validation_warnings');
            
            // Add order tracking columns
            $table->string('created_order_id')->nullable()->after('order_uuid');
            
            // Add duplicate tracking columns
            $table->boolean('is_duplicate')->default(false)->after('is_resolvable');
            $table->string('duplicate_order_id')->nullable()->after('is_duplicate');
            
            // Add processing timestamp
            $table->timestamp('processed_at')->nullable()->after('suggested_fixes');
            
            // Add meta column for flexible data storage
            $table->json('meta')->nullable()->after('processed_at');
            
            // Add foreign key constraint for company_uuid
            $table->foreign('company_uuid')->references('uuid')->on('companies')->onDelete('cascade');
            
            // Add composite indexes for performance
            $table->index(['company_uuid', 'session_uuid']);
            $table->index(['company_uuid', 'processing_status']);
            $table->index(['company_uuid', 'resolution_status']);
            $table->index(['session_uuid', 'processing_status']);
            $table->index(['session_uuid', 'is_duplicate']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('import_rows', function (Blueprint $table) {
            // Drop foreign key first
            $table->dropForeign(['company_uuid']);
            
            // Drop indexes
            $table->dropIndex(['company_uuid', 'session_uuid']);
            $table->dropIndex(['company_uuid', 'processing_status']);
            $table->dropIndex(['company_uuid', 'resolution_status']);
            $table->dropIndex(['session_uuid', 'processing_status']);
            $table->dropIndex(['session_uuid', 'is_duplicate']);
            
            // Drop columns in reverse order
            $table->dropColumn([
                'meta',
                'processed_at',
                'duplicate_order_id',
                'is_duplicate',
                'created_order_id',
                'suggestions',
                'validation_warnings',
                'validation_errors',
                'severity',
                'error_type',
                'resolution_method',
                'resolution_status',
                'processing_message',
                'processing_status',
                'deleted_at',
                'company_uuid'
            ]);
        });
    }
};