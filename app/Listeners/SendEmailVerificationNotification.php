<?php

namespace App\Listeners;

use App\Events\Registered;
use App\Mail\AccountConfirmation;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class SendEmailVerificationNotification
{
    /**
     * Handle the event.
     *
     * @param  \App\Events\Registered  $event
     * @return void
     */
    public function handle(Registered $event)
    {
        \Log::info('Listener SendEmailVerificationNotification appelé pour : ' . $event->user->id);
        $user = $event->user;

        // Génération d'un token aléatoire
        $verificationToken = Str::random(64);

        // Stockage du token haché et de sa date d'expiration
        $user->email_verification_token = Hash::make($verificationToken);
        $user->email_verification_token_expires_at = Carbon::now()->addMinutes(120);
        $user->save();

        // Construction de l'URL de vérification
        $frontendUrl = getenv('FRONT_END_URL') . "/account/verify?token=" . urlencode($verificationToken);

        // Envoi de l'email de confirmation
        Mail::to($user->email)->send(new AccountConfirmation($user->name, $frontendUrl));
    }
}