<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_reports', function (Blueprint $table) {
            $table->string('report_title')->nullable()->after('notes');
            $table->date('report_date')->nullable()->after('report_title');
            $table->enum('uploaded_by', ['patient', 'doctor'])->default('doctor')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('patient_reports', function (Blueprint $table) {
            $table->dropColumn(['report_title', 'report_date', 'uploaded_by']);
        });
    }
};
