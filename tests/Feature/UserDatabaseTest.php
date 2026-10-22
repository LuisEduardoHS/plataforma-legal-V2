<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserDatabaseTest extends TestCase
{
    public function test_user_status_is_saved_and_loaded_from_mysql(): void
    {
        $this->assertSame(
            'plataforma_legal_v2_test',
            DB::connection()->getDatabaseName()
        );

        DB::beginTransaction();

        try {
            $user = User::factory()->create();

            $savedUser = User::findOrFail($user->id);

            $this->assertSame('active', $savedUser->status);
            $this->assertTrue($savedUser->isActive());

            $savedUser->status = 'suspended';
            $savedUser->save();

            $updatedUser = User::findOrFail($user->id);

            $this->assertSame('suspended', $updatedUser->status);
            $this->assertTrue($updatedUser->isSuspended());
            $this->assertFalse($updatedUser->isActive());
        } finally {
            DB::rollBack();
        }
    }
}