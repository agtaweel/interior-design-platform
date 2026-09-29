@extends('emails.layout')

@section('content')
    <p style="margin:0 0 16px;">Hi {{ $recipientName }},</p>
    <p style="margin:0 0 20px;">
        We received a request to reset your password. Click the button below to choose a new one.
        If you didn't request this, you can safely ignore this email.
    </p>
    <div style="text-align:center;margin:0 0 8px;">
        <a href="{{ $resetUrl }}" style="display:inline-block;padding:12px 24px;background-color:#18181b;color:#ffffff;border-radius:6px;text-decoration:none;font-size:14px;font-weight:600;">
            Reset Password
        </a>
    </div>
    <p style="margin:16px 0 0;color:#71717a;font-size:13px;">
        This link expires in {{ $expiryMinutes }} minutes.
    </p>
    <p style="margin:16px 0 0;color:#a1a1aa;font-size:12px;word-break:break-all;">
        Or open this link: {{ $resetUrl }}
    </p>
@endsection
