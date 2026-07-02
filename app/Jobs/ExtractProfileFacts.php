<?php

namespace App\Jobs;

use App\Models\TaxDocument;
use App\Services\AI\PaystubFactExtractorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ExtractProfileFacts — DOC-07 / D4 proposal-creation bridge.
 *
 * Dispatched by ExtractTaxDocument::handle() after a PayStub or BenefitsGuide
 * document reaches status=Ready. Calls PaystubFactExtractorService to create
 * per-field UserTaxFact PROPOSALS (is_current=false, source_type='document_extraction').
 *
 * Proposals are invisible to skip-logic and headroom calculations until the user
 * confirms them via DurableFactsController::confirm() (the D4 gate).
 *
 * Zero Claude / HTTP calls in this job — purely reads from the already-extracted
 * extracted_data JSON and writes UserTaxFact rows.
 *
 * Queue: default (house convention — the running worker uses queue:work redis with no --queue flag)
 * Tries: 3
 * Timeout: 120s
 * Constructor: scalar document ID (avoids stale model issues)
 */
class ExtractProfileFacts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $documentId,
    ) {}

    public function handle(PaystubFactExtractorService $extractor): void
    {
        $document = TaxDocument::findOrFail($this->documentId);

        Log::info('ExtractProfileFacts: proposing facts from document', [
            'document_id' => $document->id,
            'category' => $document->category?->value,
            'tax_year' => $document->tax_year,
        ]);

        $count = $extractor->proposeFacts($document);

        Log::info('ExtractProfileFacts: proposals created', [
            'document_id' => $document->id,
            'proposals_count' => $count,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ExtractProfileFacts: job failed permanently', [
            'document_id' => $this->documentId,
            'error' => $exception->getMessage(),
        ]);
    }
}
