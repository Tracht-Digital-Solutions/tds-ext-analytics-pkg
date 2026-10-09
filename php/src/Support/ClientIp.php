<?php
declare(strict_types=1);

namespace Tds\Ext\Analytics\Support;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The client address — used for exactly two things and then dropped: the
 * country lookup and a salted hash for the rate limit. Neither the address nor
 * anything reversible to it is written anywhere.
 */
final class ClientIp
{
    public static function of(Request $request): ?string
    {
        $server = $request->getServerParams();
        $fwd = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? $request->getHeaderLine('X-Forwarded-For'));
        if ($fwd !== '') {
            // The LAST entry is the one our own proxy appended; the first is
            // whatever the client claimed.
            $parts = array_map('trim', explode(',', $fwd));
            $last = (string) end($parts);
            if (filter_var($last, FILTER_VALIDATE_IP) !== false) {
                return $last;
            }
        }
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : null;
    }

    public static function hash(string $ip, string $salt): string
    {
        return hash('sha256', $salt . '|analytics|' . $ip);
    }
}
