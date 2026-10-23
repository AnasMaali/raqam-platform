{{-- The frame of every system message (20.6): RTL, Raqam's colours, works in Gmail, Outlook and on a phone.
     $bodyHtml is the admin's template with its values already escaped by App\Mail\TemplateRenderer. --}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $siteName }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Tahoma,Arial,sans-serif;color:#18181b;direction:rtl;text-align:right">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5">
<tr><td align="center" style="padding:24px 12px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden" dir="rtl">
        <tr><td style="background:#b45309;padding:16px 24px;color:#ffffff;font-size:22px;font-weight:bold;text-align:right">{{ $siteName }}</td></tr>
        <tr><td style="padding:24px;font-size:16px;line-height:1.8;text-align:right" dir="rtl">{!! $bodyHtml !!}</td></tr>
        <tr><td style="padding:16px 24px;border-top:1px solid #e4e4e7;color:#71717a;font-size:13px;text-align:right">{{ $siteName }} — نابلس، فلسطين</td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
