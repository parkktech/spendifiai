<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ChooseScenarioRequest — validates POST /optimizer/scenarios/{year}/choose.
 *
 * PITFALL 4 (§D.6): The server ALWAYS recomputes knob vectors from its own
 * solver — client-supplied knobs are hints for 'custom', never authoritative.
 * The clamped engine output is what gets persisted.
 *
 * Allowed option_key values (§C): three canonical solver outputs + 'merged' (agreement)
 * and 'balanced'. 'custom' is allowed when knobs are provided; the server clamps
 * before persisting.
 */
class ChooseScenarioRequest extends FormRequest
{
    public const VALID_OPTION_KEYS = ['take_home', 'tax_burden', 'retirement', 'balanced', 'merged', 'custom'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rothGrid = implode(',', config('optimizer-scenarios.grids.roth_share_pct', [0, 25, 50, 75, 100]));
        $validKeys = implode(',', self::VALID_OPTION_KEYS);

        $rules = [
            'option_key' => ['required', 'string', "in:{$validKeys}"],
        ];

        // Optional knobs (for 'custom' option — server always clamps via engine).
        $rules['knobs'] = ['sometimes', 'array'];
        $rules['knobs.w4'] = ['sometimes', 'array'];
        $rules['knobs.w4.filing_status'] = ['sometimes', 'string', 'in:single_or_mfs,married_joint,head_of_household'];
        $rules['knobs.w4.dependents_under_17'] = ['sometimes', 'integer', 'min:0', 'max:99'];
        $rules['knobs.w4.other_dependents'] = ['sometimes', 'integer', 'min:0', 'max:99'];
        $rules['knobs.k401'] = ['sometimes', 'array'];
        $rules['knobs.k401.deferral_pct'] = ['sometimes', 'numeric', 'min:0', 'max:100'];
        $rules['knobs.k401.roth_share_pct'] = ['sometimes', 'integer', "in:{$rothGrid}"];
        $rules['knobs.hsa'] = ['sometimes', 'array'];
        $rules['knobs.hsa.annual_election_cents'] = ['sometimes', 'integer', 'min:0'];
        $rules['knobs.ira'] = ['sometimes', 'array'];
        $rules['knobs.ira.traditional_cents'] = ['sometimes', 'integer', 'min:0'];
        $rules['knobs.ira.roth_cents'] = ['sometimes', 'integer', 'min:0'];
        $rules['knobs.transfer'] = ['sometimes', 'array'];
        $rules['knobs.transfer.per_period_cents'] = ['sometimes', 'integer', 'min:0'];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'option_key.in' => 'Invalid option key. Must be one of: ' . implode(', ', self::VALID_OPTION_KEYS) . '.',
            'knobs.k401.roth_share_pct.in' => 'roth_share_pct must be one of: 0, 25, 50, 75, 100.',
        ];
    }
}
