<?php

namespace App\Jobs;

use App\Services\Auth\PasswordRecovery;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPasswordRecoveryLink implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(#[\SensitiveParameter] public string $email) {}

    public function handle(PasswordRecovery $recovery): void
    {
        $recovery->deliver($this->email);
    }
}
