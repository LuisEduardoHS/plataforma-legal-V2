<?php

namespace App\Services\Attachments;

use App\Enums\AttachmentStatus;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AttachmentQuota
{
    // Caller holds an exclusive lock on the persisted user row until reservation commits.
    // A resolver can override this method for future account-specific policy, not roles supplied by clients.
    public function limitFor(User $user): ?int
    {
        return config('attachments.quota.enabled', true) ? max(0, (int) config('attachments.quota.per_user_bytes', 1073741824)) : null;
    }

    public function assertCapacity(User $user, int $incoming): void
    {
        $limit = $this->limitFor($user);
        if ($limit === null) {
            return;
        }
        // Locking/current read avoids a stale REPEATABLE READ snapshot. Include all disks
        // and all states except physically confirmed deletion; association does not matter.
        $used = Attachment::where('user_id', $user->id)->where('status', '!=', AttachmentStatus::Deleted->value)
            ->lockForUpdate()->get(['size_bytes'])->sum('size_bytes');
        if ($incoming < 0 || $used > $limit || $incoming > $limit - $used) {
            throw ValidationException::withMessages(['file' => 'La cuota de almacenamiento de tu cuenta no permite esta carga.']);
        }
    }
}
