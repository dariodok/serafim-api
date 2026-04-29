<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TransactionalMessageMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $mailSubject,
        public readonly string $heading,
        public readonly string $intro,
        public readonly array $contextRows = [],
        public readonly ?string $outro = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.transactional-message',
            with: [
                'heading' => $this->heading,
                'intro' => $this->intro,
                'contextRows' => $this->contextRows,
                'outro' => $this->outro,
            ],
        );
    }
}
