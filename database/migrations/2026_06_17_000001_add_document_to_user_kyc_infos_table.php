<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_kyc_infos', function (Blueprint $table) {
            $table->string('document_type')->nullable()->after('gender');       // nin, bvn_slip, passport, drivers_license
            $table->string('document_url')->nullable()->after('document_type'); // Cloudinary or S3 URL
            $table->string('document_public_id')->nullable()->after('document_url'); // Cloudinary public_id for deletion
            $table->timestamp('document_uploaded_at')->nullable()->after('document_public_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_kyc_infos', function (Blueprint $table) {
            $table->dropColumn(['document_type', 'document_url', 'document_public_id', 'document_uploaded_at']);
        });
    }
};
