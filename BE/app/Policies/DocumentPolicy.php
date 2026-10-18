<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Support\CompanyContext;

/**
 * Who may see or change a document. Permission-based only (never role names), evaluated on the LOADED
 * record so guessed ids can never widen access.
 *
 *  document.view / document.manage -> every document of the company (managing implies seeing)
 *  document.view_own               -> own documents flagged visible_to_employee, plus company-wide
 *                                     documents flagged visible to all employees; never archived ones
 *  anything else (incl. managers)  -> nothing; team relationships grant no document access
 */
class DocumentPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission('document.view', 'document.manage', 'document.view_own');
    }

    public function viewAll(User $user): bool
    {
        return $user->hasAnyPermission('document.view', 'document.manage');
    }

    public function view(User $user, Document $document): bool
    {
        if ($document->trashed() || (int) $document->company_id !== $this->context->id()) {
            return false;
        }

        if ($this->viewAll($user)) {
            return true;
        }

        return $this->visibleToEmployee($user, $document);
    }

    public function download(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('document.manage');
    }

    public function update(User $user, Document $document): bool
    {
        return $this->manages($user, $document);
    }

    public function archive(User $user, Document $document): bool
    {
        return $this->manages($user, $document);
    }

    public function restore(User $user, Document $document): bool
    {
        return $this->manages($user, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->manages($user, $document);
    }

    /** Self-service rule, also used by DocumentQuery so lists and single reads can never disagree. */
    public function visibleToEmployee(User $user, Document $document): bool
    {
        $me = $user->employee;

        if (! $me || ! $user->hasPermission('document.view_own') || ! $document->visible_to_employee || $document->isArchived()) {
            return false;
        }

        return $document->employee_id === null || (int) $document->employee_id === (int) $me->id;
    }

    private function manages(User $user, Document $document): bool
    {
        return $user->hasPermission('document.manage')
            && ! $document->trashed()
            && (int) $document->company_id === $this->context->id();
    }
}
