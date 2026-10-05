<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @page { margin: 28px 32px 48px; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 10px; color: #1f2937; }
        h1, h2, h3, h4 { margin: 0; color: #111827; }
        .header { border-bottom: 2px solid #0d6efd; padding-bottom: 8px; margin-bottom: 12px; }
        .header td { vertical-align: middle; }
        .muted { color: #6b7280; }
        .small { font-size: 8.5px; }
        .right { text-align: right; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        .grid th { background: #f3f4f6; text-align: left; padding: 5px 6px; font-size: 9px; border-bottom: 1px solid #d1d5db; }
        .grid th.right { text-align: right; }
        .grid td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .box { border: 1px solid #e5e7eb; border-radius: 4px; padding: 8px; }
        .title-bar { background: #0d6efd; color: #fff; padding: 5px 8px; font-weight: bold; font-size: 11px; margin: 10px 0 6px; }
        .badge { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 8px; background: #e5e7eb; }
        .text-danger { color: #dc2626; font-weight: bold; }
        .text-primary { color: #1d4ed8; font-weight: bold; }
        .footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 8px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 4px; }
        .totals td { padding: 3px 6px; }
        @yield('styles')
    </style>
</head>
<body>
    @unless (isset($noHeader))
        <table class="header">
            <tr>
                <td style="width: 45%;"><img src="{{ $logo }}" style="max-height: 46px; max-width: 200px;"></td>
                <td class="right">
                    <h3>{{ $hospital->name }}</h3>
                    <div class="muted small">{{ $hospital->fullAddress() }}</div>
                    <div class="muted small">{{ $hospital->phone }} {{ $hospital->email ? '· '.$hospital->email : '' }}</div>
                    @if ($hospital->registration_no || $hospital->tax_no)
                        <div class="muted small">{{ $hospital->registration_no ? 'Reg: '.$hospital->registration_no : '' }} {{ $hospital->tax_no ? '· '.$hospital->tax_label.' No: '.$hospital->tax_no : '' }}</div>
                    @endif
                </td>
            </tr>
        </table>
    @endunless

    <div class="footer">
        <table><tr>
            <td>@yield('footer', $hospital->name)</td>
            <td class="right">Printed {{ now()->format('d M Y H:i') }}</td>
        </tr></table>
    </div>

    @yield('content')
</body>
</html>
