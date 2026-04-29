<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TransactionalEmailService;
use Illuminate\Http\Request;

class AdminMailController extends Controller
{
    public function __construct(private readonly TransactionalEmailService $mailService)
    {
    }

    public function config()
    {
        return response()->json($this->mailService->configurationSummary());
    }

    public function sendTest(Request $request)
    {
        $data = $request->validate([
            'to' => ['required', 'email:rfc'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->mailService->sendTestEmail(
            to: $data['to'],
            subject: $data['subject'] ?? null,
            message: $data['message'] ?? null,
        );

        return response()->json([
            'message' => 'Correo de prueba enviado.',
            'to' => $data['to'],
            'mailer' => config('mail.default'),
        ]);
    }
}
