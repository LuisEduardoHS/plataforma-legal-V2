<?php

namespace Tests\Unit;

use App\Enums\AttachmentStatus;
use App\Models\Attachment;
use App\Models\User;
use App\Policies\AttachmentPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttachmentPolicyTest extends TestCase
{
    public static function accessCases(): array
    {
        return [
            'active owner' => ['active', 1, AttachmentStatus::Available, 'private', true],
            'another owner' => ['active', 2, AttachmentStatus::Available, 'private', false],
            'suspended owner' => ['suspended', 1, AttachmentStatus::Available, 'private', false],
            'disabled owner' => ['disabled', 1, AttachmentStatus::Available, 'private', false],
            'owner pending deletion' => ['pending_deletion', 1, AttachmentStatus::Available, 'private', false],
            'upload incomplete' => ['active', 1, AttachmentStatus::PendingUpload, 'private', false],
            'file pending deletion' => ['active', 1, AttachmentStatus::PendingDeletion, 'private', false],
            'deleted file' => ['active', 1, AttachmentStatus::Deleted, 'private', false],
            'unsupported visibility' => ['active', 1, AttachmentStatus::Available, 'public', false],
        ];
    }

    #[DataProvider('accessCases')]
    public function test_view_requires_active_owner_private_visibility_and_available_status(string $accountStatus, int $ownerId, AttachmentStatus $status, string $visibility, bool $allowed): void
    {
        $user = new User;
        $user->id = 1;
        $user->exists = true;
        $user->status = $accountStatus;
        $attachment = new Attachment;
        $attachment->forceFill(['user_id' => $ownerId, 'status' => $status, 'visibility' => $visibility]);

        $this->assertSame($allowed, (new AttachmentPolicy)->view($user, $attachment));
    }

    public function test_unsaved_user_cannot_create_attachments(): void
    {
        $user = new User;
        $user->status = 'active';
        $this->assertFalse((new AttachmentPolicy)->create($user));
    }
}
