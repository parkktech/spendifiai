<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StorageConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'driver' => 'required|in:local,s3',
            's3_bucket' => 'required_if:driver,s3|string',
            's3_region' => 'required_if:driver,s3|string',
            's3_key' => 'required_if:driver,s3|string',
            's3_secret' => 'required_if:driver,s3|string',
        ];
    }
}
