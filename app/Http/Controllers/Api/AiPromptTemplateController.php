<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

final class AiPromptTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = \Illuminate\Support\Facades\DB::table('hpbrain_ai_prompt_templates')
            ->where('tenant_id', $this->tenantId($request))
            ->orderByDesc('created_date')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return response()->json($rows);
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = \Illuminate\Support\Facades\DB::table('hpbrain_ai_prompt_templates')
            ->where('tenant_id', $this->tenantId($request))
            ->where('id', $id)
            ->first();

        return $row ? response()->json((array) $row) : response()->json(['error' => 'prompt_template_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prompt_key'         => ['required', 'string', 'max:255'],
            'version'            => ['required', 'integer'],
            'name'               => ['required', 'string', 'max:255'],
            'description'        => ['nullable', 'string'],
            'purpose'            => ['nullable', 'string', 'max:255'],
            'system_prompt'      => ['required', 'string'],
            'user_prompt_template' => ['required', 'string'],
            'response_schema'    => ['nullable', 'array'],
            'allowed_roles'      => ['nullable', 'array'],
            'data_sources'       => ['nullable', 'array'],
            'model_capability'   => ['nullable', 'string', 'max:255'],
            'generation_settings'=> ['nullable', 'array'],
            'safety_profile'     => ['nullable', 'string', 'max:255'],
            'status'             => ['nullable', 'string', 'max:50'],
            'change_summary'     => ['nullable', 'string'],
        ]);

        $data['created_by'] = $this->actorId($request);

        \Illuminate\Support\Facades\DB::table('hpbrain_ai_prompt_templates')->insert([
            // Generated here: MySQL's UUID() does not exist on SQLite, so no
            // test could reach this write.
            'id'                   => Uuid::uuid4()->toString(),
            'tenant_id'            => $this->tenantId($request),
            'prompt_key'           => $data['prompt_key'],
            'version'              => $data['version'],
            'name'                 => $data['name'],
            'description'          => $data['description'] ?? null,
            'purpose'              => $data['purpose'] ?? null,
            'system_prompt'        => $data['system_prompt'],
            'user_prompt_template' => $data['user_prompt_template'],
            'response_schema'      => json_encode($data['response_schema'] ?? []),
            'allowed_roles'        => json_encode($data['allowed_roles'] ?? []),
            'data_sources'         => json_encode($data['data_sources'] ?? []),
            'model_capability'     => $data['model_capability'] ?? null,
            'generation_settings'  => json_encode($data['generation_settings'] ?? []),
            'safety_profile'       => $data['safety_profile'] ?? 'standard',
            'status'               => $data['status'] ?? 'draft',
            'change_summary'       => $data['change_summary'] ?? null,
            'created_by'           => $data['created_by'],
            'created_date'         => now()->format('Y-m-d H:i:s'),
            'updated_date'         => now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json(['ok' => true], 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'name'               => ['sometimes', 'string', 'max:255'],
            'description'        => ['sometimes', 'nullable', 'string'],
            'status'             => ['sometimes', 'nullable', 'string', 'max:50'],
            'change_summary'     => ['sometimes', 'nullable', 'string'],
        ]);

        \Illuminate\Support\Facades\DB::table('hpbrain_ai_prompt_templates')
            ->where('tenant_id', $this->tenantId($request))
            ->where('id', $id)
            ->update(array_merge($data, ['updated_date' => now()->format('Y-m-d H:i:s')]));

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, string $tenantId, string $id): JsonResponse
    {
        \Illuminate\Support\Facades\DB::table('hpbrain_ai_prompt_templates')
            ->where('tenant_id', $this->tenantId($request))
            ->where('id', $id)
            ->delete();

        return response()->json(['ok' => true]);
    }

    public function versions(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = \Illuminate\Support\Facades\DB::table('hpbrain_ai_prompt_templates')
            ->where('tenant_id', $this->tenantId($request))
            ->where('id', $id)
            ->first();

        if (!$row) {
            return response()->json(['error' => 'prompt_template_not_found'], 404);
        }

        $versions = \Illuminate\Support\Facades\DB::table('hpbrain_ai_prompt_templates')
            ->where('tenant_id', $this->tenantId($request))
            ->where('prompt_key', $row->prompt_key)
            ->orderByDesc('version')
            ->get(['version', 'name', 'status'])
            ->all();

        return response()->json($versions);
    }

    public function render(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = \Illuminate\Support\Facades\DB::table('hpbrain_ai_prompt_templates')
            ->where('tenant_id', $this->tenantId($request))
            ->where('id', $id)
            ->first();

        if (!$row) {
            return response()->json(['error' => 'prompt_template_not_found'], 404);
        }

        $context = $request->validate(['context' => ['nullable', 'array']])['context'] ?? [];
        $template = (string) $row->user_prompt_template;

        foreach ($context as $key => $value) {
            $template = str_replace('{{'.$key.'}}', (string) $value, $template);
        }

        return response()->json(['rendered' => $template]);
    }
}
