<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDependentRequest;
use App\Http\Requests\UpdateDependentRequest;
use App\Models\Dependent;
use Illuminate\Http\JsonResponse;

class DependentController extends Controller
{
    /**
     * List all dependents for the user (household-scoped).
     */
    public function index(): JsonResponse
    {
        $user = auth()->user();
        $userIds = $user->householdUserIds();

        $dependents = Dependent::whereIn('user_id', $userIds)
            ->orderBy('name')
            ->get();

        return response()->json([
            'dependents' => $dependents->map(fn (Dependent $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'date_of_birth' => $d->date_of_birth->format('Y-m-d'),
                'relationship' => $d->relationship,
                'is_student' => $d->is_student,
                'is_disabled' => $d->is_disabled,
                'lives_with_you' => $d->lives_with_you,
                'months_lived_with_you' => $d->months_lived_with_you,
                'is_claimed' => $d->is_claimed,
                'tax_year' => $d->tax_year,
                'age' => $d->age,
                'qualifies_for_child_tax_credit' => $d->qualifiesForChildTaxCredit(),
                'added_by' => $d->user_id,
            ]),
        ]);
    }

    /**
     * Add a new dependent.
     */
    public function store(StoreDependentRequest $request): JsonResponse
    {
        $user = auth()->user();

        $dependent = Dependent::create([
            ...$request->validated(),
            'user_id' => $user->id,
            'household_id' => $user->household_id,
        ]);

        return response()->json([
            'message' => 'Dependent added.',
            'dependent' => [
                'id' => $dependent->id,
                'name' => $dependent->name,
                'date_of_birth' => $dependent->date_of_birth->format('Y-m-d'),
                'relationship' => $dependent->relationship,
                'tax_year' => $dependent->tax_year,
                'age' => $dependent->age,
                'qualifies_for_child_tax_credit' => $dependent->qualifiesForChildTaxCredit(),
            ],
        ], 201);
    }

    /**
     * Update an existing dependent.
     */
    public function update(UpdateDependentRequest $request, Dependent $dependent): JsonResponse
    {
        $this->authorize('update', $dependent);

        $dependent->update($request->validated());

        return response()->json([
            'message' => 'Dependent updated.',
            'dependent' => [
                'id' => $dependent->id,
                'name' => $dependent->name,
                'date_of_birth' => $dependent->date_of_birth->format('Y-m-d'),
                'relationship' => $dependent->relationship,
                'tax_year' => $dependent->tax_year,
                'age' => $dependent->age,
                'qualifies_for_child_tax_credit' => $dependent->qualifiesForChildTaxCredit(),
            ],
        ]);
    }

    /**
     * Delete a dependent.
     */
    public function destroy(Dependent $dependent): JsonResponse
    {
        $this->authorize('delete', $dependent);

        $dependent->delete();

        return response()->json(['message' => 'Dependent removed.']);
    }
}
