<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coyote_cert_storage', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->string('store_key', 255)->unique();
            $table->mediumText('value');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coyote_cert_storage');
    }
};
