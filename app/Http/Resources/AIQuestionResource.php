<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AIQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'question'      => $this->question,
            'options'        => $this->options,
            'ai_confidence' => $this->confidence ? (float) $this->confidence : null,
            'user_answer'   => $this->user_answer,
            'status'        => $this->status,
            'question_type' => $this->question_type,
            'answered_at'   => $this->answered_at?->toIso8601String(),
            'created_at'    => $this->created_at?->toIso8601String(),

            'transaction' => new TransactionResource($this->whenLoaded('transaction')),
        ];
    }
}
