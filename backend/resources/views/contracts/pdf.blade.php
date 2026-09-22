<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Contract {{ $data['contract']['contract_no'] ?? '' }}</title>
    <style>
        {{--
            Plain, dompdf-friendly CSS only (no flex/grid — dompdf's renderer is table/CSS2.1
            era), matching resources/views/proposals/pdf.blade.php's convention. Internal-only
            document for the office's/client's records (PROJECT_CONTEXT.md Sprint 5) — no
            cost/margin fields exist on contracts to begin with, so there's nothing here to
            withhold the way the proposal PDF withholds subtotal/markup/fees/discount.
        --}}
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 20px; margin-bottom: 2px; }
        h2 { font-size: 14px; margin-top: 22px; margin-bottom: 6px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
        .muted { color: #666; }
        .header-table { width: 100%; margin-bottom: 12px; }
        .header-table td { vertical-align: top; }
        .meta-table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .meta-table td { border: 1px solid #ddd; padding: 6px 8px; font-size: 11px; }
        .meta-table td.label { width: 35%; background-color: #f2f2f2; font-weight: bold; }
        .grand-total { margin-top: 16px; text-align: right; font-size: 16px; font-weight: bold; }
        .section-body { white-space: pre-wrap; }
        .status-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; background: #eef; font-size: 11px; }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="width: 60%;">
                <h1>{{ $data['organization']['name'] ?? 'Contract' }}</h1>
                @if (!empty($data['organization']['legal_name']))
                    <div class="muted">{{ $data['organization']['legal_name'] }}</div>
                @endif
                <div class="muted">
                    {{ $data['organization']['phone'] ?? '' }}
                    @if (!empty($data['organization']['phone']) && !empty($data['organization']['email']))
                        &middot;
                    @endif
                    {{ $data['organization']['email'] ?? '' }}
                </div>
            </td>
            <td style="width: 40%; text-align: right;">
                <div><strong>Contract</strong> {{ $data['contract']['contract_no'] ?? '' }}</div>
                <div class="status-badge">{{ strtoupper($data['contract']['status'] ?? '') }}</div>
                @if (!empty($data['contract']['signed_at']))
                    <div class="muted">Signed: {{ \Illuminate\Support\Carbon::parse($data['contract']['signed_at'])->toFormattedDateString() }}</div>
                @endif
            </td>
        </tr>
    </table>

    <h2>Project &amp; Client</h2>
    <table class="header-table">
        <tr>
            <td style="width: 50%;">
                <strong>Project:</strong> {{ $data['project']['name'] ?? '' }}
                ({{ $data['project']['code'] ?? '' }})<br>
                @if (!empty($data['property']))
                    <strong>Property:</strong> {{ $data['property']['type'] ?? '' }}
                    @if (!empty($data['property']['compound'])) — {{ $data['property']['compound'] }} @endif
                    <br>{{ $data['property']['address'] ?? '' }}
                @endif
            </td>
            <td style="width: 50%;">
                @if (!empty($data['client']))
                    <strong>Client:</strong> {{ $data['client']['name'] ?? '' }}<br>
                    {{ $data['client']['phone'] ?? '' }}<br>
                    {{ $data['client']['email'] ?? '' }}
                @endif
            </td>
        </tr>
    </table>

    <h2>Contract Details</h2>
    <table class="meta-table">
        <tr>
            <td class="label">Contract No.</td>
            <td>{{ $data['contract']['contract_no'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="label">Source Proposal Version</td>
            <td>v{{ $data['contract']['proposal_version_no'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="label">Start Date</td>
            <td>{{ !empty($data['contract']['start_date']) ? \Illuminate\Support\Carbon::parse($data['contract']['start_date'])->toFormattedDateString() : '—' }}</td>
        </tr>
        <tr>
            <td class="label">End Date</td>
            <td>{{ !empty($data['contract']['end_date']) ? \Illuminate\Support\Carbon::parse($data['contract']['end_date'])->toFormattedDateString() : '—' }}</td>
        </tr>
    </table>

    @php
        $terms = $data['terms'] ?? [];
        $sections = [
            'timeline' => 'Timeline',
            'payment_plan' => 'Payment Plan',
            'terms' => 'Terms & Conditions',
            'exclusions' => 'Exclusions',
            'warranty_period' => 'Warranty Period',
            'cancellation_policy' => 'Cancellation Policy',
        ];
    @endphp

    @foreach ($sections as $key => $label)
        @if (!empty($terms[$key]))
            <h2>{{ $label }}</h2>
            <div class="section-body">{{ is_array($terms[$key]) ? implode("\n", $terms[$key]) : $terms[$key] }}</div>
        @endif
    @endforeach

    <div class="grand-total">
        Contract Value: {{ number_format((float) ($data['contract']['contract_value'] ?? 0), 2) }}
        {{ $data['organization']['currency'] ?? 'EGP' }}
    </div>
</body>
</html>
