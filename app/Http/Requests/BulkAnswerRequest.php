<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Individual ownership checked in controller loop
    }

    public function rules(): array
    {
        return [
            'answers'               => 'required|array',
            'answers.*.question_id' => 'required|integer|exists:ai_questions,id',
            'answers.*.answer'      => 'required|string|max:200',
        ];
    }
}
