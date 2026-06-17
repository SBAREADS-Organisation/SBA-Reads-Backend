<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Remove the "com." prefix from all existing product_id and audio_product_id values
        DB::statement("UPDATE books SET product_id = REPLACE(product_id, 'com.sbareads.', 'sbareads.') WHERE product_id LIKE 'com.sbareads.%'");
        DB::statement("UPDATE books SET audio_product_id = REPLACE(audio_product_id, 'com.sbareads.', 'sbareads.') WHERE audio_product_id LIKE 'com.sbareads.%'");
    }

    public function down(): void
    {
        DB::statement("UPDATE books SET product_id = REPLACE(product_id, 'sbareads.', 'com.sbareads.') WHERE product_id LIKE 'sbareads.%'");
        DB::statement("UPDATE books SET audio_product_id = REPLACE(audio_product_id, 'sbareads.', 'com.sbareads.') WHERE audio_product_id LIKE 'sbareads.%'");
    }
};
