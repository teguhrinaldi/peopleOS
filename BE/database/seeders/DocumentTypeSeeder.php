<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Services\Documents\DocumentTypeService;
use App\Support\CompanyContext;
use Illuminate\Database\Seeder;

/**
 * Default document types. Safe for production and idempotent (never overwrites edited types):
 *   php artisan db:seed --class=DocumentTypeSeeder
 */
class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        if (! Company::query()->exists()) {
            $this->command?->warn('No company yet: create the company first, then run DocumentTypeSeeder.');

            return;
        }

        app(CompanyContext::class)->forget();
        $created = app(DocumentTypeService::class)->installDefaults();
        $this->command?->info("Document types created: {$created}");
    }
}
