<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('coyotecert.database.connection') ?: null;
    }

    public function up(): void
    {
        // Same layout as CoyoteCert's DatabaseStorage::createTableSql(): store_key is the primary key
        Schema::create($this->table(), function (Blueprint $table) {
            $table->string('store_key', 255)->primary();
            $table->mediumText('value');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('coyotecert.database.table', 'coyote_cert_storage');
    }
};
