<?php

namespace App\Events;

use App\Models\TaxDocument;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by ExtractTaxDocument after the document reaches status=Ready.
 *
 * DOC-03: This event is the staleness signal consumed by:
 *   - 12-04 plan's staleness listener (GenerateOptimizationReport dispatch)
 *   - ExtractProfileFacts dispatch (for PayStub / BenefitsGuide categories)
 *
 * NOT fired on: failed classification, below-confidence-gate, splitter path,
 * extraction error — only the successful terminal (Ready) path fires this.
 *
 * SerializesModels ensures the TaxDocument is safely re-hydrated by queued listeners.
 *
 * Event contract (stable — do not rename properties):
 *   document: the TaxDocument that was successfully extracted
 *             exposes document->user_id, document->tax_year, document->category, document->id
 */
class TaxDocumentExtracted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly TaxDocument $document,
    ) {}
}
