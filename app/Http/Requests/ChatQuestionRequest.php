<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChatQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('question'));
    }

    public function rules(): array
    {
        return [
            'message' => 'required|string|max:500',
        ];
    }
}
