<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Proposal {{ $data['project']['name'] ?? '' }} v{{ $data['proposal']['version_no'] ?? '' }}</title>
    <style>
        {{--
            Plain, dompdf-friendly CSS only (no flex/grid — dompdf's renderer is table/CSS2.1
            era). This template renders EXACTLY the same fields as PublicProposalResource /
            GET /public/proposals/{token} — never material_unit_cost, labor_unit_cost,
            other_unit_cost, source_boq_item_id, or the subtotal/markup/fees/discount
            breakdown. Only `grand_total` is ever printed from the pricing block.
        --}}
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 20px; margin-bottom: 2px; }
        h2 { font-size: 14px; margin-top: 22px; margin-bottom: 6px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
        .muted { color: #666; }
        .header-table { width: 100%; margin-bottom: 12px; }
        .header-table td { vertical-align: top; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.items th, table.items td { border: 1px solid #ddd; padding: 6px 8px; font-size: 11px; text-align: left; }
        table.items th { background-color: #f2f2f2; }
        table.items td.num { text-align: right; }
        .grand-total { margin-top: 16px; text-align: right; font-size: 16px; font-weight: bold; }
        .section-body { white-space: pre-wrap; }
        .status-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; background: #eef; font-size: 11px; }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="width: 60%;">
                <h1>{{ $data['organization']['name'] ?? 'Proposal' }}</h1>
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
                <div><strong>Proposal</strong> v{{ $data['proposal']['version_no'] ?? '' }}</div>
                <div class="status-badge">{{ strtoupper($data['proposal']['status'] ?? '') }}</div>
                @if (!empty($data['proposal']['sent_at']))
                    <div class="muted">Sent: {{ \Illuminate\Support\Carbon::parse($data['proposal']['sent_at'])->toFormattedDateString() }}</div>
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

    @php
        $content = $data['content'] ?? [];
        $sections = [
            'cover_note' => 'Cover Note',
            'scope' => 'Scope of Work',
            'exclusions' => 'Exclusions',
            'timeline' => 'Timeline',
            'terms' => 'Terms & Conditions',
            'payment_plan' => 'Payment Plan',
        ];
    @endphp

    @foreach ($sections as $key => $label)
        @if (!empty($content[$key]))
            <h2>{{ $label }}</h2>
            <div class="section-body">{{ is_array($content[$key]) ? implode("\n", $content[$key]) : $content[$key] }}</div>
        @endif
    @endforeach

    <h2>Bill of Quantities</h2>
    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th>Unit</th>
                <th class="num">Qty</th>
                <th class="num">Unit Price</th>
                <th class="num">Line Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($data['items'] ?? [] as $item)
                <tr>
                    <td>{{ $item['description'] }}</td>
                    <td>{{ $item['unit'] }}</td>
                    <td class="num">{{ number_format((float) $item['quantity'], 2) }}</td>
                    <td class="num">{{ number_format((float) $item['unit_price'], 2) }}</td>
                    <td class="num">{{ number_format((float) $item['line_total'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="muted">No line items.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="grand-total">
        Grand Total: {{ number_format((float) ($data['pricing']['grand_total'] ?? 0), 2) }}
        {{ $data['organization']['currency'] ?? 'EGP' }}
    </div>
</body>
</html>
