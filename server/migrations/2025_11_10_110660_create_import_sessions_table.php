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
        Schema::create("import_sessions", function (Blueprint $table) {
            $table->uuid("uuid")->primary();
            $table->string("public_id")->unique();
            $table->uuid("company_uuid")->index();
            $table->uuid("template_uuid")->nullable();
            $table->uuid("file_uuid")->nullable();
            $table->string("name");
            $table->enum("status", [
                "created",
                "validating", 
                "mapping",
                "processing",
                "completed",
                "failed",
                "cancelled"
            ])->default("created");
            $table->boolean("is_dry_run")->default(false);
            $table->json("dry_run_summary")->nullable();
            $table->integer("estimated_success_count")->default(0);
            $table->integer("estimated_warning_count")->default(0);
            $table->integer("estimated_error_count")->default(0);
            $table->integer("total_rows")->default(0);
            $table->integer("processed_rows")->default(0);
            $table->integer("failed_rows")->default(0);
            $table->json("errors")->nullable();
            $table->json("meta")->nullable();
            $table->timestamp("started_at")->nullable();
            $table->timestamp("completed_at")->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign key constraints
            $table->foreign("company_uuid")->references("uuid")->on("companies")->onDelete("cascade");
            $table->foreign("template_uuid")->references("uuid")->on("import_templates")->onDelete("set null");
            $table->foreign("file_uuid")->references("uuid")->on("files")->onDelete("set null");
            
            // Indexes
            $table->index(["template_uuid", "status", "created_at"]);
            $table->index(["file_uuid"]);
            $table->index(["company_uuid", "created_at"]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("import_sessions");
    }
};
