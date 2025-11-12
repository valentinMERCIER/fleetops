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
        Schema::create("import_rows", function (Blueprint $table) {
            $table->uuid("uuid")->primary();
            $table->string("public_id")->unique();
            $table->uuid("session_uuid");
            $table->integer("row_number");
            $table->integer("line_number")->nullable();
            $table->json("original_data");
            $table->json("mapped_data")->nullable();
            $table->json("normalized_data")->nullable();
            $table->enum("status", [
                "pending",
                "mapping_failed", 
                "validation_failed",
                "processing",
                "processed",
                "failed",
                "skipped",
                "duplicate"
            ])->default("pending");
            $table->json("errors")->nullable();
            $table->json("warnings")->nullable();
            $table->enum("error_severity", ["critical", "error", "warning", "info"])->nullable();
            $table->uuid("order_uuid")->nullable();
            $table->boolean("is_resolvable")->default(true);
            $table->json("suggested_fixes")->nullable();
            $table->timestamps();
            
            // Foreign key constraints
            $table->foreign("session_uuid")->references("uuid")->on("import_sessions")->onDelete("cascade");
            $table->foreign("order_uuid")->references("uuid")->on("orders")->onDelete("set null");
            
            // Indexes
            $table->index(["session_uuid", "status"]);
            $table->index(["session_uuid", "row_number"]);
            $table->index(["error_severity"]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("import_rows");
    }
};
