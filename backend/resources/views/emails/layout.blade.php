{{--
    Shared minimal HTML email shell — inline styles throughout since most mail clients strip
    <style> blocks or ignore external stylesheets. Every transactional email in this app
    (OTP delivery, password reset, client portal notifications) extends this via
    @extends('emails.layout'), filling the `content` section only.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? config('app.name') }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="max-width:480px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e4e4e7;">
                    <tr>
                        <td style="padding:24px 28px;border-bottom:1px solid #e4e4e7;">
                            <span style="font-size:14px;font-weight:600;color:#18181b;">{{ config('app.name') }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;color:#27272a;font-size:15px;line-height:1.6;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px;border-top:1px solid #e4e4e7;color:#a1a1aa;font-size:12px;">
                            This is an automated message — please don't reply directly to this email.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
