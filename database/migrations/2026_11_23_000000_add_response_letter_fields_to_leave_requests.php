<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR final-processing response letter.
 *
 * The HR reviewer is the last step of the approval chain: they check the
 * balance, may override the number of granted days, assign the official
 * "Kumb. Na" reference, write the body of the response letter and dispatch
 * it. These fields are additive and nullable so existing requests, existing
 * clients and the seeded fixtures keep working untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = Schema::getColumnListing('leave_requests');

        Schema::table('leave_requests', function (Blueprint $table) use ($columns) {
            if (! in_array('reference_number', $columns, true)) {
                $table->string('reference_number', 100)->nullable()->after('ref');
            }
            if (! in_array('approved_days', $columns, true)) {
                $table->unsignedInteger('approved_days')->nullable()->after('days');
            }
            if (! in_array('letter_body', $columns, true)) {
                $table->text('letter_body')->nullable()->after('approved_days');
            }
            if (! in_array('letter_issued_at', $columns, true)) {
                $table->timestamp('letter_issued_at')->nullable()->after('letter_body');
            }
        });
    }

    public function down(): void
    {
        $columns = Schema::getColumnListing('leave_requests');

        Schema::table('leave_requests', function (Blueprint $table) use ($columns) {
            $drop = array_values(array_filter(
                ['reference_number', 'approved_days', 'letter_body', 'letter_issued_at'],
                fn (string $column) => in_array($column, $columns, true),
            ));

            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
