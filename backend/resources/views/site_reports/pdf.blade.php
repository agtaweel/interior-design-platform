<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Site Report {{ $report->id }}</title>
    <style>
        {{-- Plain, dompdf-friendly CSS only (no flex/grid), matching contracts/pdf.blade.php's
             and proposals/pdf.blade.php's convention. No cost/margin data on a site report. --}}
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 20px; margin-bottom: 2px; }
        h2 { font-size: 14px; margin-top: 22px; margin-bottom: 6px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
        .muted { color: #666; }
        .header-table { width: 100%; margin-bottom: 12px; }
        .header-table td { vertical-align: top; }
        .section-body { white-space: pre-wrap; }
        .photo-grid td { padding: 4px; }
        .photo-grid img { width: 160px; height: 120px; object-fit: cover; }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="width: 60%;">
                <h1>{{ $report->project->name }}</h1>
                <div class="muted">{{ $report->project->code }}</div>
            </td>
            <td style="width: 40%; text-align: right;">
                <div><strong>Site Report</strong> #{{ $report->id }}</div>
                <div class="muted">{{ \Illuminate\Support\Carbon::parse($report->report_date)->toFormattedDateString() }}</div>
                <div class="muted">Reported by: {{ $report->reportedBy->name }}</div>
            </td>
        </tr>
    </table>

    <h2>Work Done</h2>
    <div class="section-body">{{ $report->work_done }}</div>

    @if ($report->issues)
        <h2>Issues</h2>
        <div class="section-body">{{ $report->issues }}</div>
    @endif

    @if ($report->decisions)
        <h2>Decisions</h2>
        <div class="section-body">{{ $report->decisions }}</div>
    @endif

    @if ($report->media->isNotEmpty())
        <h2>Photos</h2>
        <table class="photo-grid">
            <tr>
                @foreach ($report->media as $photo)
                    <td><img src="{{ $photo->getPath() }}" alt=""></td>
                    @if ($loop->iteration % 3 === 0 && ! $loop->last)
            </tr><tr>
                    @endif
                @endforeach
            </tr>
        </table>
    @endif
</body>
</html>
