<?php

namespace App\Listeners;

use App\Events\PasswordResetRequested;
use App\Mail\ResetPassword;
use Illuminate\Support\Facades\Mail;

class SendPasswordResetEmail
{
    /**
     * Handle the event.
     *
     * @param  \App\Events\PasswordResetRequested  $event
     * @return void
     */
    public function handle(PasswordResetRequested $event)
    {
        // Create reset URL for frontend
        $resetUrl = getenv('FRONT_END_URL') . '/change-password?token='
            . $event->token . '&email=' . urlencode($event->user->email);

        // Send email with reset link
        Mail::to($event->user->email)
            ->send(new ResetPassword($event->user->name, $resetUrl));
    }
}