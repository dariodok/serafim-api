<?php

namespace App\Services;

use App\Mail\TransactionalMessageMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class TransactionalEmailService
{
    public function sendMessage(
        string $to,
        string $subject,
        string $heading,
        string $intro,
        array $contextRows = [],
        ?string $outro = null,
    ): void {
        $to = trim($to);

        if ($to === '') {
            throw ValidationException::withMessages([
                'to' => 'Debes indicar un destinatario para enviar el correo.',
            ]);
        }

        Mail::to($to)->send(new TransactionalMessageMail(
            mailSubject: trim($subject),
            heading: trim($heading),
            intro: trim($intro),
            contextRows: $contextRows,
            outro: $outro ? trim($outro) : null,
        ));
    }

    public function sendTestEmail(string $to, ?string $subject = null, ?string $message = null): void
    {
        $subject = trim((string) ($subject ?: 'Prueba de correo Serafim'));
        $message = trim((string) ($message ?: 'Este es un correo de prueba enviado desde la infraestructura de Serafim.'));

        $this->sendMessage(
            to: $to,
            subject: $subject,
            heading: 'Correo de prueba',
            intro: $message,
            contextRows: [
                'Aplicacion' => config('app.name'),
                'Entorno' => config('app.env'),
                'Mailer' => config('mail.default'),
                'Fecha' => now()->format('d/m/Y H:i'),
            ],
            outro: 'Si recibiste este mensaje, la configuracion SMTP quedo operativa.',
        );
    }

    public function configurationSummary(): array
    {
        return [
            'mailer' => config('mail.default'),
            'host' => config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port'),
            'scheme' => config('mail.mailers.smtp.scheme'),
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'reply_to_address' => config('mail.reply_to.address'),
            'reply_to_name' => config('mail.reply_to.name'),
            'configured' => $this->hasRequiredSmtpConfiguration(),
        ];
    }

    private function hasRequiredSmtpConfiguration(): bool
    {
        return filled(config('mail.mailers.smtp.host'))
            && filled(config('mail.mailers.smtp.port'))
            && filled(config('mail.mailers.smtp.username'))
            && filled(config('mail.mailers.smtp.password'))
            && filled(config('mail.from.address'));
    }
}
