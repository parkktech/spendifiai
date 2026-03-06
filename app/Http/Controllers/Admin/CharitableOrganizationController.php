<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCharitableOrganizationRequest;
use App\Http\Requests\Admin\UpdateCharitableOrganizationRequest;
use App\Http\Resources\CharitableOrganizationResource;
use App\Models\CharitableOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CharitableOrganizationController extends Controller
{
    public function stats(): JsonResponse
    {
        $total = CharitableOrganization::count();
        $active = CharitableOrganization::where('is_active', true)->count();
        $featured = CharitableOrganization::where('is_featured', true)->count();
        $withDonateUrl = CharitableOrganization::whereNotNull('donate_url')->count();

        $categories = CharitableOrganization::selectRaw('category, count(*) as count')
            ->whereNotNull('category')
            ->groupBy('category')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['category' => $row->category, 'count' => $row->count]);

        $recentlyAdded = CharitableOrganizationResource::collection(
            CharitableOrganization::orderByDesc('created_at')->limit(10)->get()
        );

        return response()->json([
            'total_charities' => $total,
            'active_charities' => $active,
            'featured_charities' => $featured,
            'with_donate_url' => $withDonateUrl,
            'categories' => $categories,
            'recently_added' => $recentlyAdded,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = CharitableOrganization::query();

        if ($search = $request->input('search')) {
            $query->where('name', 'ilike', "%{$search}%");
        }

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        if ($request->has('featured')) {
            $query->where('is_featured', $request->boolean('featured'));
        }

        $charities = $query->orderBy('sort_order')->orderBy('name')->paginate(
            $request->integer('per_page', 25)
        );

        return response()->json([
            'charities' => CharitableOrganizationResource::collection($charities),
            'meta' => [
                'current_page' => $charities->currentPage(),
                'last_page' => $charities->lastPage(),
                'per_page' => $charities->perPage(),
                'total' => $charities->total(),
            ],
        ]);
    }

    public function store(StoreCharitableOrganizationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = Str::slug($data['name']);

        $charity = CharitableOrganization::create($data);

        return response()->json([
            'charity' => new CharitableOrganizationResource($charity),
            'message' => 'Charitable organization created successfully',
        ], 201);
    }

    public function show(CharitableOrganization $charity): JsonResponse
    {
        return response()->json([
            'charity' => new CharitableOrganizationResource($charity),
        ]);
    }

    public function update(UpdateCharitableOrganizationRequest $request, CharitableOrganization $charity): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $charity->update($data);

        return response()->json([
            'charity' => new CharitableOrganizationResource($charity->fresh()),
            'message' => 'Charitable organization updated successfully',
        ]);
    }

    public function destroy(CharitableOrganization $charity): JsonResponse
    {
        $charity->delete();

        return response()->json(['message' => 'Charitable organization deleted successfully']);
    }
}
