<?php

use App\Domains\Taxation\Models\TaxType;
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
        Schema::table('tax_types', function (Blueprint $table) {
            $table->string('tax_category_code', 4)
                ->default(TaxType::TAX_CATEGORY_CODE_STANDARD);
            $table->string('tax_exemption_reason', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tax_types', function (Blueprint $table) {
            $table->dropColumn(['tax_category_code', 'tax_exemption_reason']);
        });
    }
};
