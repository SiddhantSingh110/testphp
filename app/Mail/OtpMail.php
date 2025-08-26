<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public $otp;
    public $userName;

    public function __construct($otp, $userName = null)
    {
        $this->otp = $otp;
        $this->userName = $userName ?: 'User';
    }

    public function build()
    {
        return $this->from('no-reply.myhealth@webshark.in', 'Webshark My Health')
                    ->subject('Your Login Code - Webshark My Health')
                    ->view('emails.otp');
    }
}