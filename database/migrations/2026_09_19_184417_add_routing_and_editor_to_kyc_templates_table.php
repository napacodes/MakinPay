<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Two facts a template could not carry.
 *
 * `routing` is the builder's Routing control: a template may insist on a
 * human even where a provider covers the applicant's country. Nothing stored
 * this before, so the queue's "Manual only" reading could only ever mean "no
 * provider covers this country" and an admin had no way to say otherwise.
 * It defaults to `auto`, which is exactly today's behaviour, so an upgraded
 * installation changes nothing until someone flips a template.
 *
 * `updated_by` is who last saved the template. The list has always shown a
 * date with no name beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_templates', function (Blueprint $table): void {
            if (! Schema::hasColumn('kyc_templates', 'routing')) {
                $table->string('routing', 20)->default('auto')->after('applicable_to');
            }

            if (! Schema::hasColumn('kyc_templates', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('status')
                    ->constrained('admins')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('kyc_templates', function (Blueprint $table): void {
            if (Schema::hasColumn('kyc_templates', 'updated_by')) {
                $table->dropConstrainedForeignId('updated_by');
            }

            if (Schema::hasColumn('kyc_templates', 'routing')) {
                $table->dropColumn('routing');
            }
        });
    }
};
