<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Documents\DocumentTypeRequest;
use App\Http\Resources\DocumentResources;
use App\Models\DocumentType;
use App\Services\Documents\DocumentTypeService;
use App\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentTypeController extends ApiController
{
    public function __construct(
        private readonly DocumentTypeService $types,
        private readonly CompanyContext $context,
    ) {}

    /** Active types for everyone with a document permission; `include_inactive=1` + full config for managers. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['include_inactive' => ['nullable', 'boolean']]);
        $user = $request->user();

        if (! $user->hasAnyPermission('document.view', 'document.manage', 'document.view_own')) {
            throw ApiException::make('FORBIDDEN', 'You do not have permission to view documents.', 403);
        }

        $manager = $user->hasPermission('document.manage');
        $types = $this->types->list($manager && $request->boolean('include_inactive'));

        return $this->ok($types->map(fn (DocumentType $t) => DocumentResources::type($t, $manager))->values());
    }

    public function store(DocumentTypeRequest $request): JsonResponse
    {
        return $this->ok(DocumentResources::type($this->types->create($request->user(), $request->payload()), true), 201);
    }

    public function update(DocumentTypeRequest $request, int $id): JsonResponse
    {
        $type = DocumentType::query()->where('company_id', $this->context->id())->whereKey($id)->first()
            ?? throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);

        return $this->ok(DocumentResources::type($this->types->update($request->user(), $type, $request->payload()), true));
    }
}
