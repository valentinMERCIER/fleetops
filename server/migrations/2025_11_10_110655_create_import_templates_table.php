<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('import_templates', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('public_id')->unique();
            $table->uuid('company_uuid')->index();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('import_type')->default('orders'); // orders, vehicles, drivers, etc.
            $table->json('field_mappings'); // {csv_column: model_field}
            $table->json('validation_rules')->nullable(); // Laravel validation rules
            $table->json('default_values')->nullable(); // Default values for missing fields
            $table->enum('duplicate_strategy', ['skip', 'update', 'create_new', 'merge'])
                  ->default('skip');
            $table->json('duplicate_check_fields')->nullable(); // Fields to check for duplicates
            $table->json('options')->nullable(); // Additional options
            $table->json('meta')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign key constraints
            $table->foreign('company_uuid')->references('uuid')->on('companies')->onDelete('cascade');
            
            // Indexes
            $table->index(['company_uuid', 'import_type']);
            $table->index(['is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_templates');
    }
};