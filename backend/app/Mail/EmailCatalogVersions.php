<?php

namespace App\Mail;

/**
 * Every text the catalogue ever shipped for each template (subject, body, on or off), as a hash, oldest first; the last
 * one is the catalogue now. A row that still holds one of them was never edited, so emails:install brings it up to date.
 * When a text in EmailCatalog changes, its new hash goes at the end of its list (EmailTemplatesTest says which).
 */
final class EmailCatalogVersions
{
    public const HASHES = [
        'admin_login_code' => ['b84edcddd7b72b9afa090cff04ca50d060638f41f6bdb4c8a89c30de1419e741'],
        'admin_new_device' => ['1907523f4ead7319afcc8ef008fae77add4ccd047c9219bdc2ab778afe5bd352'],
        'admin_password_changed' => ['5d47af928188e1b2205f2f21e2e3cb05efb5e7f7ff65489e75b619c3b1b66fdb'],
        'admin_contact_message' => ['dcbb60913012c3b1071c3c77a2772505a1123e3731a924ef2ea8980f7439ac64'],
        'visitor_contact_reply' => ['491c067ee2a053d15a19791e2c5666d79390e82592f67057b6498064b49f8d57'],
        'admin_provider_failure' => ['40b9529f3df3326a09e6cb1840cc63fe4b7130c8f7371f7bc62fcb6149a805cc'],
        'admin_video_unavailable' => ['18f5e73437adc1e9594191e87341cfec8d8283e5379e0ca9bcfced59f263dd57'],
        'admin_job_failed' => ['3eb7e078f7bf67cb480f94e07aa5022c79c673e8b3295708ffdcb846d2e6d9c7'],
        'admin_disk_space' => ['97d732f37dcb8dccb50e6a3b7ac8a441af9b3839a983ba718af15a697a0717cf'],
        'admin_ad_ending' => ['810a338e454b4f2591c1afcf127207b14589bb4cc47c181b6c9e931e365d7454'],
        'admin_weekly_digest' => ['f5968d254ea52e152438c19fae4a1bae26a889be239c09d738688cd3face1db1'],
        'booking_received' => ['8693e64bf6666e395100b033521d10f8e839934d3a47912ca8b4d64f33207f68', '873ca7b03ba56fbf52caed3bcc1544d0a2673a0a12869b86a1cbebc132d97ccc'],
        'booking_status_changed' => ['6017d77905a26ea93372ce957499679a16c71b4093fb91cb7c1f02fa9547f9db', '751f698ee88163564928dd64e14444add54b0e053c4dd9ba8c4c7d6a0b738130'],
        'payment_receipt' => ['a7e582671f61437b5bed45fe79389f139dbafa824d0edf7d75e85c2cc905d076', '2f0d5153fb8e6ac633630711fa424fc8731cf5500bc37ac3c40728ed53900d93'],
        'account_verify_code' => ['7b7eeb25a9977a7a2146a31486b65bcffb533fdf15279cdaaeaf31a40b3a4a5e', '4085ff9f08915ff79eab2bbae1fc4981b6121a1270bfc0d3ccb38721c3644258'],
        'password_reset_code' => ['955664e2bf25187f12f315667981ad82dba33cf0d2fc8603c7e04f6bd1abc553', 'b558894ec1e1767cc1cc6b21740302350f12a70f263210fd71d9199aa20775b3'],
        'visitor_newsletter_code' => ['c929e28e218e441f9ab68c571b526a1541984640d246f12a835b4e4365a71f72'],
        'admin_booking_received' => ['1e028b564e18e36979a762ed7d62c0ba1928212b212cf49371a11cf302cad9c5'],
        'payment_refunded' => ['81a6fb54ba767ff27181144fefc1ab7c8c8f4ef0d392271f9a6afb3ca1faf929'],
        'admin_payment_alert' => ['86fb658fd9e81d6edf4f0e85a2bf309896d417afaf5f0b0aec7aa8e59f994e94'],
        'account_password_changed' => ['c8f4a3ee7e36a5379b9e84a4229468777a5de5ecf9d2a536c1c2d195d0370ecc'],
    ];

    public static function hash(string $subject, string $body, bool $active): string
    {
        return hash('sha256', $subject."\x1F".$body."\x1F".($active ? '1' : '0'));
    }
}
