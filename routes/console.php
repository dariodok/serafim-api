<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\TransactionalEmailService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('serafim:mail-test {to?} {--subject=Prueba de correo Serafim} {--message=Este es un correo de prueba enviado desde Serafim.}', function (TransactionalEmailService $mailService) {
    $to = $this->argument('to') ?: env('MAIL_TEST_RECIPIENT');

    if (!$to) {
        $this->error('Indica un destinatario o configura MAIL_TEST_RECIPIENT.');
        return self::FAILURE;
    }

    $mailService->sendTestEmail(
        to: (string) $to,
        subject: (string) $this->option('subject'),
        message: (string) $this->option('message'),
    );

    $this->info(sprintf('Correo de prueba enviado a %s usando %s.', $to, config('mail.default')));

    return self::SUCCESS;
})->purpose('Envia un correo de prueba usando la configuracion SMTP actual');
