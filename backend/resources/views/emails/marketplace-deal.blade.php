@extends('emails.layout')

@section('content')
    <p style="margin:0 0 16px;">Hi {{ $recipientName }},</p>
    <p style="margin:0 0 20px;">
        {{ $organizationName }} sent you a deal for <strong>{{ $projectName }}</strong>.
        Log in to your Fitout account to review and approve it.
    </p>
    <div style="text-align:center;margin:0 0 8px;">
        <a href="{{ $dashboardUrl }}" style="display:inline-block;padding:12px 24px;background-color:#18181b;color:#ffffff;border-radius:6px;text-decoration:none;font-size:14px;font-weight:600;">
            View Deal
        </a>
    </div>
    <p style="margin:16px 0 0;color:#a1a1aa;font-size:12px;word-break:break-all;">
        Or open this link: {{ $dashboardUrl }}
    </p>
@endsection
