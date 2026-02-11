<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnswerQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('question'));
    }

    public function rules(): array
    {
        return [
            'answer' => 'required|string|max:200',
        ];
    }
}
