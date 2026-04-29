<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0;padding:24px;background:#0f1720;color:#dbe4ee;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;margin:0 auto;background:#16212c;border:1px solid #243447;border-radius:12px;">
        <tr>
            <td style="padding:32px 32px 20px;">
                <div style="font-size:12px;letter-spacing:1.6px;text-transform:uppercase;color:#7dd3fc;font-weight:700;margin-bottom:12px;">
                    {{ config('app.name') }}
                </div>
                <h1 style="margin:0 0 12px;font-size:24px;line-height:1.3;color:#f8fafc;">{{ $heading }}</h1>
                <p style="margin:0;font-size:15px;line-height:1.65;color:#cbd5e1;">{{ $intro }}</p>
            </td>
        </tr>

        @if(!empty($contextRows))
            <tr>
                <td style="padding:0 32px 12px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;border:1px solid #243447;border-radius:8px;overflow:hidden;">
                        @foreach($contextRows as $label => $value)
                            <tr>
                                <td style="padding:12px 14px;border-bottom:1px solid #243447;background:#111923;font-size:12px;font-weight:700;letter-spacing:0.8px;text-transform:uppercase;color:#8fa4b8;width:38%;">
                                    {{ $label }}
                                </td>
                                <td style="padding:12px 14px;border-bottom:1px solid #243447;background:#111923;font-size:14px;color:#f8fafc;">
                                    {{ $value }}
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        @endif

        @if(!empty($outro))
            <tr>
                <td style="padding:8px 32px 24px;">
                    <p style="margin:0;font-size:14px;line-height:1.6;color:#cbd5e1;">{{ $outro }}</p>
                </td>
            </tr>
        @endif
    </table>
</body>
</html>
