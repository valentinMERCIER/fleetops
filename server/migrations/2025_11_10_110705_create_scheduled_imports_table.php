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
        Schema::create("scheduled_imports", function (Blueprint $table) {
            $table->uuid("uuid")->primary();
            $table->string("public_id")->unique();
            $table->uuid("company_uuid")->index();
            $table->uuid("template_uuid");
            $table->string("name");
            $table->string("cron_expression");
            $table->string("file_source_type");
            $table->json("file_source_config");
            $table->enum("status", ["active", "paused", "disabled", "failed"])->default("active");
            $table->timestamp("last_run_at")->nullable();
            $table->timestamp("next_run_at")->nullable();
            $table->json("last_run_result")->nullable();
            $table->integer("run_count")->default(0);
            $table->json("options")->nullable();
            $table->json("meta")->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign key constraints
            $table->foreign("company_uuid")->references("uuid")->on("companies")->onDelete("cascade");
            $table->foreign("template_uuid")->references("uuid")->on("import_templates")->onDelete("cascade");
            
            // Indexes
            $table->index(["status", "next_run_at"]);
            $table->index(["company_uuid", "status"]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("scheduled_imports");
    }
};
