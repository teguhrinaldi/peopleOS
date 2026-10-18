<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Documents\DocumentArchiveRequest;
use App\Http\Requests\Api\Documents\DocumentIndexRequest;
use App\Http\Requests\Api\Documents\DocumentStoreRequest;
use App\Http\Requests\Api\Documents\DocumentUpdateRequest;
use App\Http\Resources\DocumentResources;
use App\Models\Document;
use App\Models\User;
use App\Policies\DocumentPolicy;
use App\Services\Documents\DocumentQuery;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents for employee self-service (scope=mine) and HR (scope=all + management).
 *
 * Ids are resolved inside the caller's visible set: a document the caller may not see answers
 * 404 NOT_FOUND exactly like one that does not exist, so ids cannot be probed. Management endpoints
 * check document.manage BEFORE loading anything (403 without revealing whether the id exists).
 */
class DocumentController extends ApiController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentQuery $query,
        private readonly DocumentStorage $storage,
        private readonly DocumentPolicy $policy,
    ) {}

    public function index(DocumentIndexRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($request->scope() === 'all') {
            if (! $this->policy->viewAll($user)) {
                throw ApiException::make('FORBIDDEN', 'You do not have permission to view all documents.', 403);
            }
            $base = $this->query->all();
        } else {
            if (! $user->hasPermission('document.view_own')) {
                throw ApiException::make('FORBIDDEN', 'You do not have permission to view documents.', 403);
            }
            $this->employee($request);
            $base = $this->query->selfService($user);
        }

        $page = $this->query->filter($base->with(DocumentResources::with()), $request->filters())
            ->paginate($this->perPage($request));

        return $this->paginated($page, fn (Document $d) => DocumentResources::document($d, $user));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $document = $this->visible($request->user(), $id);

        return $this->ok(DocumentResources::document($document->load(DocumentResources::with()), $request->user()));
    }

    /** Private file stream. The policy runs on the loaded record; the storage path is never revealed. */
    public function download(Request $request, int $id): StreamedResponse
    {
        $document = $this->visible($request->user(), $id);
        $this->authorize('download', $document);

        return $this->storage->download($document);
    }

    public function store(DocumentStoreRequest $request): JsonResponse
    {
        $document = $this->documents->upload($request->user(), $request->file('file'), $request->payload());

        return $this->ok(DocumentResources::document($document->load(DocumentResources::with()), $request->user()), 201);
    }

    public function update(DocumentUpdateRequest $request, int $id): JsonResponse
    {
        $document = $this->documents->update($request->user(), $this->managed($id), $request->payload());

        return $this->ok(DocumentResources::document($document->load(DocumentResources::with()), $request->user()));
    }

    public function archive(DocumentArchiveRequest $request, int $id): JsonResponse
    {
        $document = $this->documents->archive($request->user(), $this->managed($id), $request->reason());

        return $this->ok(DocumentResources::document($document->load(DocumentResources::with()), $request->user()));
    }

    public function restore(DocumentArchiveRequest $request, int $id): JsonResponse
    {
        $document = $this->documents->restore($request->user(), $this->managed($id));

        return $this->ok(DocumentResources::document($document->load(DocumentResources::with()), $request->user()));
    }

    public function destroy(DocumentArchiveRequest $request, int $id): JsonResponse
    {
        $this->documents->delete($request->user(), $this->managed($id));

        return $this->ok(['id' => $id, 'deleted' => true]);
    }

    private function visible(User $user, int $id): Document
    {
        if (! $this->policy->viewAny($user)) {
            throw ApiException::make('FORBIDDEN', 'You do not have permission to view documents.', 403);
        }

        return $this->query->findVisible($user, $id)
            ?? throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);
    }

    private function managed(int $id): Document
    {
        return $this->query->all()->whereKey($id)->first()
            ?? throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);
    }
}
