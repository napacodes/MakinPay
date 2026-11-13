<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One row per uploaded KYC document, carrying a digest of the file's
         * contents rather than the file.
         *
         * A table instead of a column on kyc_submissions: the question being
         * asked is "does this digest appear under more than one account",
         * which is an indexed GROUP BY here and a full scan of a JSON column
         * there. It also keeps a submission's several documents separable —
         * a shared utility bill is not the same finding as a shared passport.
         *
         * The digest is one-way and is never rendered. It answers only whether
         * two files are byte-identical; it cannot reconstruct the document.
         */
        Schema::create('kyc_document_hashes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kyc_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The template field the file was uploaded against, so a match can
            // say which document it was.
            $table->string('field_label');

            // sha256, hex encoded.
            $table->string('hash', 64);
            $table->timestamps();

            // A resubmission replaces the digest for that field rather than
            // stacking a second one behind it.
            $table->unique(['kyc_submission_id', 'field_label'], 'kyc_document_hashes_field_unique');

            // The duplicate lookup groups on the digest and then reads the
            // owning accounts out of the same index.
            $table->index(['hash', 'user_id'], 'kyc_document_hashes_match_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_document_hashes');
    }
};
