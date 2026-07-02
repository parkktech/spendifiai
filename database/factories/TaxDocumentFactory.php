<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\TaxDocumentCategory;
use App\Models\TaxDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxDocument>
 */
class TaxDocumentFactory extends Factory
{
    protected $model = TaxDocument::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'original_filename' => 'paystub.pdf',
            'stored_path' => 'documents/paystub.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'file_size' => 102400,
            'file_hash' => $this->faker->sha256(),
            'tax_year' => 2026,
            'category' => TaxDocumentCategory::W2,
            'status' => DocumentStatus::Ready,
            'classification_confidence' => 0.95,
            'extracted_data' => null,
            'metadata' => null,
        ];
    }

    /**
     * A ready pay stub document.
     */
    public function paystub(): static
    {
        return $this->state([
            'original_filename' => 'paystub.pdf',
            'category' => TaxDocumentCategory::PayStub,
            'status' => DocumentStatus::Ready,
        ]);
    }
}
