<?php

namespace Up2Dev\UserTotp\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailOtpCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $code)
    {
    }

    public function build(): self
    {
        return $this->subject(config('user-totp.email_otp_subject', 'Votre code de connexion'))
            ->view('user-totp::email-otp-code');
    }
}
