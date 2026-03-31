<?php

namespace App\Policies;

use App\Models\TaxDocument;
use App\Models\User;

class TaxDocumentPolicy
{
    /**
     * Any authenticated user can list documents (filtered by user scope).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Owner or linked accountant can view a document.
     */
    public function view(User $user, TaxDocument $document): bool
    {
        if ($user->id === $document->user_id) {
            return true;
        }

        // Accountant linked to document owner via accountant_clients
        return $user->isAccountant()
            && $user->clients()->where('client_id', $document->user_id)->exists();
    }

    /**
     * Any authenticated user can upload documents.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Only the document owner can soft-delete (accountants cannot delete client docs).
     */
    public function delete(User $user, TaxDocument $document): bool
    {
        return $user->id === $document->user_id;
    }

    /**
     * Only admin users can permanently purge soft-deleted documents.
     */
    public function purge(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Owner or linked accountant can view audit log.
     */
    public function viewAuditLog(User $user, TaxDocument $document): bool
    {
        return $this->view($user, $document);
    }

    /**
     * Owner or linked accountant can annotate a document.
     */
    public function annotate(User $user, TaxDocument $document): bool
    {
        return $this->view($user, $document);
    }

    /**
     * Only accountant users can create document requests.
     */
    public function requestDocument(User $user): bool
    {
        return $user->isAccountant();
    }
}
