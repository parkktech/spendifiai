<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for answering an optimization question in the guided interview.
 *
 * The answer is a user-supplied string (max 500 chars) representing their
 * response to the question. It may be a treatment label (e.g. 'standard_mileage'),
 * a range label (e.g. 'under_25k'), or a free-text confirmation ('yes'/'no').
 *
 * SAFE-03: this request must NEVER accept an estimated_value_cents field or
 * any dollar amount as input. Dollar computations are the sole domain of
 * TaxRulesEngineService and are never user-provided.
 *
 * Authorization: delegated to InterviewController (policy check via $this->authorize()).
 */
class AnswerOptimizationQuestionRequest extends FormRequest
{
    /**
     * Authorization is handled in InterviewController::answer() via
     * $this->authorize('update', $interview). This request itself
     * does not re-check (avoids double policy evaluation).
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // User's answer to the optimization question.
            // May be a label ('standard_mileage'), range ('under_25k'), or 'yes'/'no'.
            // Max 500 chars to cover free-text confirmations.
            'answer' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'answer.required' => 'Please provide an answer to the question.',
            'answer.max' => 'Answer must be 500 characters or fewer.',
        ];
    }
}
