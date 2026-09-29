@extends('emails.layout')

@section('content')
    <p style="margin:0 0 16px;">Hi {{ $recipientName }},</p>
    @if ($outcome === 'approved')
        <p style="margin:0 0 20px;">
            We've recorded your approval of the <strong>{{ $documentLabel }} {{ $documentNumber }}</strong> for
            <strong>{{ $projectName }}</strong>. {{ $organizationName }} has been notified and will follow up on
            next steps.
        </p>
    @elseif ($outcome === 'rejected')
        <p style="margin:0 0 20px;">
            We've recorded your rejection of the <strong>{{ $documentLabel }} {{ $documentNumber }}</strong> for
            <strong>{{ $projectName }}</strong>. {{ $organizationName }} has been notified.
        </p>
    @else
        <p style="margin:0 0 20px;">
            We've passed your requested changes on <strong>{{ $documentLabel }} {{ $documentNumber }}</strong> for
            <strong>{{ $projectName }}</strong> along to {{ $organizationName }}. They'll follow up with a revised
            version.
        </p>
    @endif
@endsection
