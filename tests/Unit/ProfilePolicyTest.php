<?php

namespace Tests\Unit;

use App\Models\User;
use App\Policies\ProfilePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProfilePolicyTest extends TestCase
{
    public static function accountStates(): array
    {
        return [['active', true], ['suspended', false], ['disabled', false], ['pending_deletion', false]];
    }

    #[DataProvider('accountStates')]
    public function test_owner_requires_an_active_account(string $status, bool $allowed): void
    {
        $user = new User;
        $user->id = 1;
        $user->exists = true;
        $user->status = $status;
        $this->assertSame($allowed, (new ProfilePolicy)->create($user, $user));
    }

    public function test_unsaved_user_cannot_manage_a_profile(): void
    {
        $user = new User;
        $user->status = 'active';
        $this->assertFalse((new ProfilePolicy)->create($user, $user));
    }
}
