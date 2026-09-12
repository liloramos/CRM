<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEX = 'customer_addresses_one_default_per_customer';

    public function up(): void
    {
        DB::table('customer_addresses')
            ->whereNotNull('customer_id')
            ->orderBy('customer_id')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->groupBy('customer_id')
            ->each(function ($addresses): void {
                $defaultId = $addresses->firstWhere('is_default', true)?->id ?? $addresses->first()?->id;

                foreach ($addresses as $address) {
                    $customer = DB::table('customers')->where('id', $address->customer_id)->first();
                    if ($customer === null) {
                        continue;
                    }

                    DB::table('customer_addresses')->where('id', $address->id)->update([
                        'company_id' => $customer->company_id,
                        'label' => trim((string) $address->label) !== '' ? $address->label : 'Principal',
                        'is_default' => (int) $address->id === (int) $defaultId,
                        'updated_at' => now(),
                    ]);
                }
            });

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON customer_addresses (customer_id) WHERE is_default = true AND customer_id IS NOT NULL',
            self::INDEX,
        ));
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
