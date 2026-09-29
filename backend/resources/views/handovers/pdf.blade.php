<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Handover — {{ $handover->project->code }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 20px; margin-bottom: 2px; }
        h2 { font-size: 14px; margin-top: 22px; margin-bottom: 6px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
        .muted { color: #666; }
        .meta-table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .meta-table td { border: 1px solid #ddd; padding: 6px 8px; font-size: 11px; }
        .meta-table td.label { width: 35%; background-color: #f2f2f2; font-weight: bold; }
        .section-body { white-space: pre-wrap; }
        .signoff { margin-top: 40px; }
    </style>
</head>
<body>
    <h1>Certificate of Handover</h1>
    <div class="muted">{{ $handover->project->name }} ({{ $handover->project->code }})</div>
    @if ($handover->project->client)
        <div class="muted">Client: {{ $handover->project->client->name }}</div>
    @endif

    <table class="meta-table">
        <tr>
            <td class="label">Handover Date</td>
            <td>{{ \Illuminate\Support\Carbon::parse($handover->handover_date)->toFormattedDateString() }}</td>
        </tr>
        <tr>
            <td class="label">Approved By</td>
            <td>{{ $handover->approvedBy->name }}</td>
        </tr>
        <tr>
            <td class="label">Warranty Period</td>
            <td>{{ $handover->warranty_period_months !== null ? $handover->warranty_period_months.' months' : 'N/A' }}</td>
        </tr>
    </table>

    @if ($handover->warranty_notes)
        <h2>Warranty Notes</h2>
        <div class="section-body">{{ $handover->warranty_notes }}</div>
    @endif

    @if ($handover->notes)
        <h2>Notes</h2>
        <div class="section-body">{{ $handover->notes }}</div>
    @endif

    <div class="signoff">
        This certifies that the above project has been completed and handed over to the client,
        with all mandatory snags resolved as of the handover date.
    </div>
</body>
</html>
