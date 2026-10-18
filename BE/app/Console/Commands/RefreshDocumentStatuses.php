<?php

namespace App\Console\Commands;

use App\Services\Documents\DocumentService;
use Illuminate\Console\Command;

/**
 * Hourly: brings stored document statuses in line with today's date (company timezone) and sends
 * "Document expiring" / "Document expired" notifications once per document per state.
 * Idempotent and safe to run concurrently (see DocumentService::refreshStatuses()).
 */
class RefreshDocumentStatuses extends Command
{
    protected $signature = 'documents:refresh-status';

    protected $description = 'Sync document ACTIVE/EXPIRING/EXPIRED statuses and send expiry notifications';

    public function handle(DocumentService $documents): int
    {
        $result = $documents->refreshStatuses();

        $this->info("Statuses updated: {$result['updated']} · notifications sent: {$result['notified']}");

        return self::SUCCESS;
    }
}
