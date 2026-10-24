<?php

namespace App\Booking;

/**
 * The private status link of a booking (15.9): 32 random bytes, base64url, handed to the visitor once; the database
 * keeps only its SHA-256, so the link cannot be rebuilt from a copy of the database. A plain hash is enough here (no
 * slow hash, no key): the token is 256 random bits, nothing a dictionary or a brute force could find.
 *
 * The token rides after the `#` of the site's address: browsers never send that part, so it stays out of every
 * server log and Referer header. The site's page reads it and asks the API through its own server.
 */
final class BookingLink
{
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Whether a string has the shape of a token at all (checked before anything is looked up). */
    public static function wellFormed(mixed $token): bool
    {
        return is_string($token) && preg_match('/^[A-Za-z0-9_-]{43}\z/', $token) === 1;
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('raqam.site_url'), '/').config('raqam.booking.track_path').'#'.$token;
    }
}
