{{--
    Shared shell for every generated PDF.

    mPDF renders this as a standalone document, so the styles are inline rather
    than from the compiled stylesheet, and the font is left to mPDF's Arabic
    handling (it shapes and joins the glyphs; dompdf does not).
--}}
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11pt; color: #1f2426; direction: rtl; }
        .header { border-bottom: 2px solid #2f7f79; padding-bottom: 8px; margin-bottom: 14px; }
        .center-name { font-size: 15pt; font-weight: bold; color: #1d534f; }
        .center-meta { font-size: 9pt; color: #6b767d; margin-top: 3px; }
        .doc-title { font-size: 13pt; font-weight: bold; margin: 14px 0 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 8px; text-align: right; }
        .kv th { width: 35%; color: #6b767d; font-weight: normal; font-size: 10pt; }
        .kv td { font-weight: bold; }
        .kv tr { border-bottom: 1px solid #eef0f1; }
        .data th { background: #f7f8f8; border-bottom: 2px solid #dfe3e5; font-size: 9.5pt; color: #505a60; }
        .data td { border-bottom: 1px solid #eef0f1; font-size: 9.5pt; }
        .data tfoot td { border-top: 2px solid #c6ccd0; font-weight: bold; background: #f7f8f8; }
        .num { text-align: left; direction: ltr; }
        .amount-box { background: #eef7f6; border: 1px solid #a8d5d0; padding: 12px; text-align: center; margin: 14px 0; }
        .amount-box .label { font-size: 9pt; color: #1d534f; }
        .amount-box .value { font-size: 18pt; font-weight: bold; color: #143433; margin-top: 4px; direction: ltr; }
        .footer { margin-top: 24px; padding-top: 8px; border-top: 1px solid #dfe3e5; font-size: 8.5pt; color: #98a2a8; }
        .signatures { margin-top: 32px; }
        .signatures td { text-align: center; font-size: 9pt; color: #6b767d; padding-top: 28px; }
        .sig-line { border-top: 1px solid #98a2a8; padding-top: 4px; }
        .muted { color: #6b767d; font-size: 9pt; }
        .void { color: #b04b5f; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <table>
            <tr>
                <td style="width: 65%">
                    <div class="center-name">{{ $center['name'] }}</div>
                    <div class="center-meta">
                        @if ($center['branch_name']) فرع: {{ $center['branch_name'] }} — @endif
                        @if ($center['address']) {{ $center['address'] }} @endif
                    </div>
                    <div class="center-meta">
                        @if ($center['phone']) هاتف: {{ $center['phone'] }} @endif
                        @if ($center['email']) — {{ $center['email'] }} @endif
                    </div>
                </td>
                <td class="num" style="width: 35%; font-size: 9pt; color: #6b767d;">
                    تاريخ الطباعة: {{ now()->format('Y-m-d H:i') }}
                </td>
            </tr>
        </table>
    </div>

    @yield('content')

    <div class="footer">
        {{ $center['name'] }} — مستند صادر آلياً من نظام إدارة مركز تدريب القيادة.
    </div>
</body>
</html>
