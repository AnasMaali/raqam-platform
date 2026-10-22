<?php

use App\Support\TrackingHash;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Limits and counters know a visitor by an HMAC of their network: an IPv4 address, or the /64 of an IPv6 one.

test('every address of one IPv6 /64 is the same visitor, written any way; the next /64 is another', function () {
    $one = TrackingHash::visitor('2001:db8:1:2::1');

    expect(TrackingHash::visitor('2001:db8:1:2:ffff:ffff:ffff:ffff'))->toBe($one)
        ->and(TrackingHash::visitor('2001:0DB8:0001:0002:0000:0000:0000:0abc'))->toBe($one)
        ->and(TrackingHash::visitor('2001:db8:1:3::1'))->not->toBe($one)
        ->and(TrackingHash::visitor('2001:db8:1:2::1'))->not->toContain('2001');
});

test('an IPv4 address is a visitor on its own, also when written as IPv6', function () {
    expect(TrackingHash::visitor('::ffff:203.0.113.5'))->toBe(TrackingHash::visitor('203.0.113.5'))
        ->and(TrackingHash::visitor('203.0.113.6'))->not->toBe(TrackingHash::visitor('203.0.113.5'))
        ->and(TrackingHash::network('203.0.113.5'))->toBe('203.0.113.5')
        ->and(TrackingHash::network('2001:db8:1:2:aaaa:bbbb:cccc:dddd'))->toBe('2001:db8:1:2::/64');
});

test('the request limits share the rule: a new address from the same /64 does not get a fresh minute', function () {
    config(['raqam.api.limit' => 3]);
    foreach (['2001:db8:5:5::1', '2001:db8:5:5::2', '2001:db8:5:5:abcd::3'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson('/api/v1/categories')->assertOk();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:5:5:ffff::4'])->getJson('/api/v1/categories')->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:5:6::1'])->getJson('/api/v1/categories')->assertOk();
});
