<?php

namespace App\Mail;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SupportTicketCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly SupportTicket $ticket) {}

    public function build(): self
    {
        return $this->subject('Novo chamado de suporte '.$this->ticket->code)
            ->view('mail.support-ticket-created');
    }
}
