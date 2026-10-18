<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DocumentMetaRequest;
use App\Http\Requests\Admin\DocumentTypeRequest;
use App\Http\Requests\Admin\DocumentUploadRequest;
use App\Models\Document;
use App\Models\DocumentType;
use App\Services\Admin\DocumentActions;
use App\Services\Admin\DocumentDesk;
use App\Services\Documents\DocumentTypeService;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Documents, document types and authorized file streaming. Thin: reads via DocumentDesk, writes via DocumentActions. */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentDesk $desk, private readonly DocumentActions $actions) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'document_type_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:ACTIVE,EXPIRING,EXPIRED,ARCHIVED'],
            'employee_id' => ['nullable', 'integer'], 'scope' => ['nullable', 'in:company,employee'], 'expiring_within_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'sort' => ['nullable', 'in:recent,expiry,title'],
        ]);
        $query = $filters + [];
        $query['company_only'] = ($filters['scope'] ?? null) === 'company';
        $query['employee_only'] = ($filters['scope'] ?? null) === 'employee';
        $viewer = $request->user();
        $canManage = $viewer->hasPermission('document.manage');

        return view('admin.documents.index', [
            'documents' => $this->desk->paginate($viewer, $query),
            'filters' => $filters,
            'options' => $this->desk->filterOptions($viewer),
            'desk' => $this->desk,
            'canManage' => $canManage,
            'uploadTypes' => $canManage ? $this->desk->uploadTypes() : collect(),
            'employees' => $canManage ? $this->desk->employeeOptions() : [],
        ]);
    }

    public function show(Request $request, int $document): View
    {
        $doc = $this->visible($request, $document)->load(['type', 'employee', 'uploader', 'archiver']);
        $canManage = $request->user()->hasPermission('document.manage');

        return view('admin.documents.show', [
            'document' => $doc, 'desk' => $this->desk, 'canManage' => $canManage,
            'trail' => $this->desk->trail($doc),
            'types' => $canManage ? $this->desk->uploadTypes() : collect(),
        ]);
    }

    public function file(Request $request, int $document): StreamedResponse
    {
        return $this->actions->stream($request->user(), $this->visible($request, $document), $request->boolean('preview'));
    }

    public function store(DocumentUploadRequest $request): RedirectResponse
    {
        $document = $this->actions->upload($request->user(), $request->file('file'), $request->payload());
        $toEmployee = $request->boolean('back_to_employee') && $document->employee_id;

        return ($toEmployee ? redirect()->route('admin.employees.show', [$document->employee_id, 'tab' => 'documents']) : redirect()->route('admin.documents.show', $document))
            ->with('success', "{$document->title} was uploaded.");
    }

    public function update(DocumentMetaRequest $request, int $document): RedirectResponse
    {
        $doc = $this->actions->update($request->user(), $this->managed($document), $request->validated());

        return redirect()->route('admin.documents.show', $doc)->with('success', 'Document details were saved.');
    }

    public function archive(DocumentMetaRequest $request, int $document): RedirectResponse
    {
        $doc = $this->actions->archive($request->user(), $this->managed($document), $request->validated('reason'));

        return redirect()->route('admin.documents.show', $doc)->with('success', 'The document was archived. Employees can no longer see it; you can restore it any time.');
    }

    public function restore(Request $request, int $document): RedirectResponse
    {
        $doc = $this->actions->restore($request->user(), $this->managed($document));

        return redirect()->route('admin.documents.show', $doc)->with('success', 'The document was restored.');
    }

    public function destroy(Request $request, int $document): RedirectResponse
    {
        $doc = $this->managed($document);
        $this->actions->delete($request->user(), $doc);

        return redirect()->route('admin.documents')->with('success', "{$doc->title} was deleted. The file is kept in storage for retention.");
    }

    public function types(DocumentTypeService $types, CompanyContext $context): View
    {
        $list = $types->list(true);
        $counts = Document::query()->where('company_id', $context->id())->selectRaw('document_type_id, count(*) as n')->groupBy('document_type_id')->pluck('n', 'document_type_id');

        return view('admin.documents.types', ['types' => $list, 'counts' => $counts]);
    }

    public function saveType(DocumentTypeRequest $request, CompanyContext $context, ?int $type = null): RedirectResponse
    {
        $model = $type ? DocumentType::query()->where('company_id', $context->id())->findOrFail($type) : null;
        $saved = $this->actions->saveType($request->user(), $model, $request->payload());

        return redirect()->route('admin.documents.types')->with('success', $model ? "{$saved->name} was saved." : "{$saved->name} was added.");
    }

    private function visible(Request $request, int $id): Document
    {
        return $this->desk->findVisible($request->user(), $id) ?? abort(404);
    }

    private function managed(int $id): Document
    {
        return $this->desk->findManaged($id) ?? abort(404);
    }
}
