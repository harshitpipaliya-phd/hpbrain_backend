<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AiExecutionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

final class AiProviderController extends Controller
{
    public function __construct()
    {
    }

    public function index(Request $request): JsonResponse
    {
        $providers = \App\Services\AiProviderRegistry::getAllProviders();

        return response()->json($providers);
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $provider = \App\Services\AiProviderRegistry::get($id);

        return $provider
            ? response()->json($provider)
            : response()->json(['error' => 'provider_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider_name' => ['required', 'string', 'max:100'],
            'provider_type' => ['required', 'string', 'max:100'],
            'config'        => ['nullable', 'array'],
            'priority'      => ['nullable', 'integer'],
            'is_active'     => ['nullable', 'boolean'],
        ]);

        $data['created_by'] = $this->actorId($request);

        \Illuminate\Support\Facades\DB::table('hpbrain_ai_providers')->insert([
            // Generated here: MySQL's UUID() does not exist on SQLite, so no
            // test could reach this write.
            'id'           => Uuid::uuid4()->toString(),
            'tenant_id'    => $this->tenantId($request),
            'provider_name'=> $data['provider_name'],
            'provider_type'=> $data['provider_type'],
            'config'       => json_encode($data['config'] ?? []),
            'is_active'    => $data['is_active'] ?? true,
            'priority'     => $data['priority'] ?? 0,
            'created_by'   => $data['created_by'],
            'created_date' => now()->format('Y-m-d H:i:s'),
            'updated_date' => now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json(['ok' => true], 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        \Illuminate\Support\Facades\DB::table('hpbrain_ai_providers')
            ->where('tenant_id', $this->tenantId($request))
            ->where('id', $id)
            ->update([
                'is_active'   => $request->boolean('is_active', true),
                'priority'    => $request->integer('priority', 0),
                'updated_date'=> now()->format('Y-m-d H:i:s'),
            ]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        \Illuminate\Support\Facades\DB::table('hpbrain_ai_providers')
            ->where('tenant_id', $this->tenantId($request))
            ->where('id', $id)
            ->delete();

        return response()->json(['ok' => true]);
    }

    public function test(Request $request, string $tenantId, string $id): JsonResponse
    {
        return response()->json(['status' => 'ok', 'message' => 'Provider test placeholder']);
    }

    public function setActive(Request $request, string $tenantId, string $id): JsonResponse
    {
        \Illuminate\Support\Facades\DB::table('hpbrain_ai_providers')
            ->where('tenant_id', $this->tenantId($request))
            ->update(['is_active' => false]);

        \Illuminate\Support\Facades\DB::table('hpbrain_ai_providers')
            ->where('tenant_id', $this->tenantId($request))
            ->where('id', $id)
            ->update(['is_active' => true, 'updated_date' => now()->format('Y-m-d H:i:s')]);

        return response()->json(['ok' => true]);
    }
}
