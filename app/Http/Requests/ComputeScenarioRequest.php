<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ComputeScenarioRequest — validates the mix-panel knob submission for POST /optimizer/scenarios/{year}/compute.
 *
 * Validation is a UX nicety; the engine always clamps a COPY of the knob vector (Pitfall 4 / §E).
 * Validation here catches obviously-hostile input before it hits the engine.
 *
 * Grid enforcement:
 *  - roth_share_pct must be in {0, 25, 50, 75, 100}
 *  - deferral_pct: numeric, 0–100
 *  - annual_election_cents / traditional_cents / roth_cents / per_period_cents: non-negative integers
 */
class ComputeScenarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rothGrid = implode(',', config('optimizer-scenarios.grids.roth_share_pct', [0, 25, 50, 75, 100]));

        return [
            'knobs'                                      => ['required', 'array'],
            'knobs.w4'                                   => ['sometimes', 'array'],
            'knobs.w4.filing_status'                     => ['sometimes', 'string', 'in:single_or_mfs,married_joint,head_of_household'],
            'knobs.w4.dependents_under_17'               => ['sometimes', 'integer', 'min:0', 'max:99'],
            'knobs.w4.other_dependents'                  => ['sometimes', 'integer', 'min:0', 'max:99'],
            'knobs.k401'                                 => ['sometimes', 'array'],
            'knobs.k401.deferral_pct'                    => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'knobs.k401.roth_share_pct'                  => ['sometimes', 'integer', "in:{$rothGrid}"],
            'knobs.hsa'                                  => ['sometimes', 'array'],
            'knobs.hsa.annual_election_cents'            => ['sometimes', 'integer', 'min:0'],
            'knobs.ira'                                  => ['sometimes', 'array'],
            'knobs.ira.traditional_cents'                => ['sometimes', 'integer', 'min:0'],
            'knobs.ira.roth_cents'                       => ['sometimes', 'integer', 'min:0'],
            'knobs.transfer'                             => ['sometimes', 'array'],
            'knobs.transfer.per_period_cents'            => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
