@extends('emails.layout')

@section('content')
    <p style="margin:0 0 16px;">Hi {{ $recipientName }},</p>
    <p style="margin:0 0 20px;">
        {{ $organizationName }} sent you a {{ $documentLabel }} for
        <strong>{{ $projectName }}</strong>. Use the code below to verify it's you before approving.
    </p>
    <div style="margin:0 0 20px;text-align:center;">
        <span style="display:inline-block;padding:14px 28px;background-color:#f4f4f5;border-radius:8px;font-size:28px;font-weight:700;letter-spacing:0.15em;color:#18181b;">{{ $code }}</span>
    </div>
    <p style="margin:0 0 20px;color:#71717a;font-size:13px;">
        This code expires in {{ $expiryHours }} hours and can only be used on the review page below.
    </p>
    <div style="text-align:center;margin:0 0 8px;">
        <a href="{{ $publicUrl }}" style="display:inline-block;padding:12px 24px;background-color:#18181b;color:#ffffff;border-radius:6px;text-decoration:none;font-size:14px;font-weight:600;">
            Review &amp; Approve
        </a>
    </div>
    <p style="margin:16px 0 0;color:#a1a1aa;font-size:12px;word-break:break-all;">
        Or open this link: {{ $publicUrl }}
    </p>
@endsection
