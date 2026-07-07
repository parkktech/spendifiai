<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SimulateScenarioRequest — validates the what-if calculator input for
 * POST /optimizer/scenarios/{year}/simulate.
 *
 * OWNER SEMANTICS (2026-07-06): contribution_pct_of_max is a percentage of the
 * IRS LEGAL MAXIMUM (402(g) employee deferral limit incl. catch-ups) — 100 means
 * "contribute the full legally allowed amount", NOT 100% of salary. The server
 * converts it to a payroll deferral percentage against deferral-eligible comp
 * (base wages + bonus when the plan takes deferrals from bonus checks).
 *
 * Validation is a UX nicety; the engine always clamps a COPY (Pitfall 4).
 */
class SimulateScenarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Both nullable: an empty call returns the user's CURRENT position so the
        // calculator opens where they actually are today.
        return [
            'contribution_pct_of_max' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'roth_share_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }
}
