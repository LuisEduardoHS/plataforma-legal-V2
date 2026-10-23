<?php

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\TestCase;

class UserStatusTest extends TestCase
{
    public function test_user_can_be_active(): void
    {
        $user = new User();
        $user->status = 'active';

        $this->assertTrue($user->isActive());
        $this->assertFalse($user->isSuspended());
    }

    public function test_user_can_be_suspended(): void
    {
        $user = new User();
        $user->status = 'suspended';

        $this->assertTrue($user->isSuspended());
        $this->assertFalse($user->isActive());
    }

    public function test_user_can_be_disabled(): void
    {
        $user = new User();
        $user->status = 'disabled';

        $this->assertTrue($user->isDisabled());
    }

    public function test_user_can_be_pending_deletion(): void
    {
        $user = new User();
        $user->status = 'pending_deletion';

        $this->assertTrue($user->isPendingDeletion());
    }
}