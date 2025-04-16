<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GuideApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    public $guideName;
    public $loginUrl;

    public function __construct($guideName)
    {
        $this->guideName = $guideName;
        $this->loginUrl = config('app.frontend_url', 'http://localhost:3000') . '/login';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Selamat! Anda Telah Menjadi Guide SummitCess',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.guide-approval',
        );
    }
}
