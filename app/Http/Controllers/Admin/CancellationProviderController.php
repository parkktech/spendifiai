<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkImportRequest;
use App\Http\Requests\Admin\StoreCancellationProviderRequest;
use App\Http\Requests\Admin\UpdateCancellationProviderRequest;
use App\Http\Resources\CancellationProviderResource;
use App\Models\CancellationProvider;
use App\Services\CancellationLinkFinderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CancellationProviderController extends Controller
{
    public function stats(): JsonResponse
    {
        $total = CancellationProvider::count();
        $verified = CancellationProvider::where('is_verified', true)->count();
        $unverified = $total - $verified;
        $withUrl = CancellationProvider::whereNotNull('cancellation_url')->count();

        $categories = CancellationProvider::selectRaw('category, count(*) as count')
            ->whereNotNull('category')
            ->groupBy('category')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['category' => $row->category, 'count' => $row->count]);

        $recentlyAdded = CancellationProviderResource::collection(
            CancellationProvider::orderByDesc('created_at')->limit(10)->get()
        );

        return response()->json([
            'total_providers' => $total,
            'verified_providers' => $verified,
            'unverified_providers' => $unverified,
            'with_cancellation_url' => $withUrl,
            'categories' => $categories,
            'recently_added' => $recentlyAdded,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = CancellationProvider::query();

        if ($search = $request->input('search')) {
            $query->where('company_name', 'ilike', "%{$search}%");
        }

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        if ($request->has('verified')) {
            $query->where('is_verified', $request->boolean('verified'));
        }

        if ($request->has('essential')) {
            $query->where('is_essential', $request->boolean('essential'));
        }

        $providers = $query->orderBy('company_name')->paginate(
            $request->integer('per_page', 25)
        );

        return response()->json([
            'providers' => CancellationProviderResource::collection($providers),
            'meta' => [
                'current_page' => $providers->currentPage(),
                'last_page' => $providers->lastPage(),
                'per_page' => $providers->perPage(),
                'total' => $providers->total(),
            ],
        ]);
    }

    public function store(StoreCancellationProviderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = Str::slug($data['company_name']);

        $provider = CancellationProvider::create($data);

        return response()->json([
            'provider' => new CancellationProviderResource($provider),
            'message' => 'Provider created successfully',
        ], 201);
    }

    public function show(CancellationProvider $provider): JsonResponse
    {
        return response()->json([
            'provider' => new CancellationProviderResource($provider),
        ]);
    }

    public function update(UpdateCancellationProviderRequest $request, CancellationProvider $provider): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['company_name'])) {
            $data['slug'] = Str::slug($data['company_name']);
        }

        $provider->update($data);

        return response()->json([
            'provider' => new CancellationProviderResource($provider->fresh()),
            'message' => 'Provider updated successfully',
        ]);
    }

    public function destroy(CancellationProvider $provider): JsonResponse
    {
        $provider->delete();

        return response()->json(['message' => 'Provider deleted successfully']);
    }

    public function bulkImport(BulkImportRequest $request): JsonResponse
    {
        $imported = 0;
        $updated = 0;

        foreach ($request->validated()['providers'] as $data) {
            $slug = Str::slug($data['company_name']);
            $existing = CancellationProvider::where('slug', $slug)->first();

            if ($existing) {
                $existing->update(array_merge($data, ['slug' => $slug]));
                $updated++;
            } else {
                CancellationProvider::create(array_merge($data, ['slug' => $slug]));
                $imported++;
            }
        }

        return response()->json([
            'message' => "Imported {$imported} new, updated {$updated} existing providers",
            'imported' => $imported,
            'updated' => $updated,
        ]);
    }

    public function findCancellationLink(CancellationProvider $provider, CancellationLinkFinderService $service): JsonResponse
    {
        $result = $service->findCancellationLink($provider);

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], 422);
        }

        // Auto-update the provider with AI findings
        $provider->update(array_filter([
            'cancellation_url' => $result['cancellation_url'],
            'cancellation_phone' => $result['cancellation_phone'],
            'cancellation_instructions' => $result['cancellation_instructions'],
            'difficulty' => $result['difficulty'],
        ]));

        return response()->json([
            'provider' => new CancellationProviderResource($provider->fresh()),
            'ai_result' => $result,
            'message' => 'Cancellation link found and saved',
        ]);
    }
}
