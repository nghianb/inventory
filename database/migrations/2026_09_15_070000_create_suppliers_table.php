<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('note')->nullable();
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX suppliers_name_unique ON suppliers (lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
