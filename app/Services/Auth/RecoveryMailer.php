<?php

namespace App\Services\Auth;

use App\Exceptions\PasswordRecoveryUnavailable;

class RecoveryMailer
{
    public function name(): string
    {
        if (app()->environment('testing')) {
            return 'array';
        }
        $name = config('mail.default');
        $transport = config('mail.mailers.'.$name.'.transport');
        if (in_array($transport, ['smtp', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun', 'sendmail'], true)) {
            return $name;
        }
        if (! app()->environment('production')) {
            // Never let the log/failover mailer persist a recovery link.
            return 'array';
        }
        throw new PasswordRecoveryUnavailable;
    }

    public function assertAvailable(): void
    {
        $this->name();
        $queue = config('queue.connections.'.config('queue.default').'.driver');
        if (app()->environment('production') && ! in_array($queue, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw new PasswordRecoveryUnavailable;
        }
    }
}
