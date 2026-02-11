<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountPurposeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('account'));
    }

    public function rules(): array
    {
        return [
            'purpose'                 => 'required|in:personal,business,mixed,investment',
            'nickname'                => 'nullable|string|max:100',
            'business_name'           => 'nullable|string|max:200',
            'tax_entity_type'         => 'nullable|in:sole_prop,llc,s_corp,c_corp,partnership,personal',
            'ein'                     => 'nullable|string|max:20',
            'include_in_spending'     => 'nullable|boolean',
            'include_in_tax_tracking' => 'nullable|boolean',
        ];
    }
}
