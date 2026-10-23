{{-- The weekly newsletter as Brevo sends it (19.6, 19.7): RTL, Raqam's colours, tables for Gmail and Outlook, works on a
     phone. Every text is escaped. The two {{ }} below are Brevo's own tags (written @{{ }} so Blade leaves them):
     Brevo's one-click unsubscribe, and the subscriber's own link to our unsubscribe page (a contact attribute). --}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Tahoma,Arial,sans-serif;color:#18181b;direction:rtl;text-align:right">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5">
<tr><td align="center" style="padding:24px 12px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden" dir="rtl">
        <tr><td style="background:#b45309;padding:16px 24px;color:#ffffff;font-size:22px;font-weight:bold;text-align:right">
            <a href="{{ $siteUrl }}" style="color:#ffffff;text-decoration:none">{{ $siteName }}</a>
        </td></tr>
        @if (filled($intro))
            <tr><td style="padding:24px 24px 8px;font-size:16px;line-height:1.8;text-align:right">{!! nl2br(e($intro), false) !!}</td></tr>
        @endif
        @foreach ($posts as $post)
            <tr><td style="padding:16px 24px;border-bottom:1px solid #e4e4e7;text-align:right">
                @if ($post['image'])
                    <a href="{{ $post['url'] }}"><img src="{{ $post['image'] }}" alt="{{ $post['alt'] }}" width="552" style="display:block;width:100%;max-width:552px;height:auto;border:0;border-radius:8px;margin:0 0 12px"></a>
                @endif
                <a href="{{ $post['url'] }}" style="color:#18181b;font-size:18px;font-weight:bold;text-decoration:none;line-height:1.6">{{ $post['title'] }}</a>
                @if (filled($post['excerpt']))
                    <p style="margin:8px 0 0;color:#52525b;font-size:15px;line-height:1.8">{{ $post['excerpt'] }}</p>
                @endif
            </td></tr>
        @endforeach
        <tr><td style="padding:16px 24px;color:#71717a;font-size:13px;line-height:1.8;text-align:right">
            وصلتك هذه الرسالة لأنك اشتركت في نشرة {{ $siteName }} البريدية.<br>
            <a href="@{{ unsubscribe }}" style="color:#71717a">إلغاء الاشتراك</a> —
            <a href="@{{ contact.RAQAM_UNSUB }}" style="color:#71717a">صفحة إلغاء الاشتراك</a>
        </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
