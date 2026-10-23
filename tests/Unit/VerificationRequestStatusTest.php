<?php

namespace Tests\Unit;

use App\Enums\VerificationRequestStatus as Status;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VerificationRequestStatusTest extends TestCase
{
    public static function transitions(): array
    {
        return [
            [Status::Draft, Status::Pending, true],
            [Status::Draft, Status::Approved, false],
            [Status::Pending, Status::Approved, true],
            [Status::Pending, Status::Rejected, true],
            [Status::Pending, Status::Invalidated, true],
            [Status::Pending, Status::Revoked, false],
            [Status::Approved, Status::Revoked, true],
            [Status::Approved, Status::Invalidated, true],
            [Status::Approved, Status::Rejected, false],
            [Status::Rejected, Status::Pending, false],
            [Status::Revoked, Status::Approved, false],
            [Status::Invalidated, Status::Approved, false],
            [Status::Pending, Status::Pending, false],
        ];
    }

    #[DataProvider('transitions')]
    public function test_transitions_do_not_skip_submission_overwrite_decisions_or_reopen_history(Status $from, Status $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }
}
