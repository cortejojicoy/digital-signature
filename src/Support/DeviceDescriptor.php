<?php

namespace Kukux\DigitalSignature\Support;

/**
 * Describes a browser from its User-Agent plus the hints the page reports.
 *
 * Only ever used to label a device for its owner ("Safari on iPhone"). It is
 * never a security signal — the key is — so a small parser that gets the
 * common cases right beats a dependency.
 */
final class DeviceDescriptor
{
    public function __construct(
        public readonly string $deviceType,   // desktop | mobile | tablet | unknown
        public readonly ?string $platform,    // iPhone, iPad, Android, Mac, Windows, Linux, ChromeOS
        public readonly ?string $browser,     // "Chrome 131", "Safari 18"
    ) {}

    /**
     * @param  array{touch?: bool, mobile?: bool, platform?: string}  $hints
     *   What the page read from navigator: touch = maxTouchPoints > 1,
     *   mobile / platform from navigator.userAgentData where supported.
     */
    public static function fromUserAgent(?string $ua, array $hints = []): self
    {
        $ua = (string) $ua;
        $touch = (bool) ($hints['touch'] ?? false);

        [$platform, $type] = match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPod') => ['iPhone', 'mobile'],
            str_contains($ua, 'iPad')                                 => ['iPad', 'tablet'],
            // iPadOS Safari asks for the desktop site and reports itself as
            // a Mac; a touch screen is what gives it away.
            str_contains($ua, 'Macintosh') && $touch                  => ['iPad', 'tablet'],
            str_contains($ua, 'Android')                              => ['Android', str_contains($ua, 'Mobile') ? 'mobile' : 'tablet'],
            str_contains($ua, 'CrOS')                                 => ['ChromeOS', 'desktop'],
            str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X') => ['Mac', 'desktop'],
            str_contains($ua, 'Windows')                              => ['Windows', 'desktop'],
            str_contains($ua, 'Linux')                                => ['Linux', 'desktop'],
            default                                                   => [null, 'unknown'],
        };

        if ($platform === null && isset($hints['platform'])) {
            $platform = self::normalisePlatformHint((string) $hints['platform']);
            $type = $platform !== null ? 'desktop' : 'unknown';
        }

        if (($hints['mobile'] ?? false) === true && $type === 'desktop') {
            $type = 'mobile';
        }

        return new self($type, $platform, self::browser($ua));
    }

    private static function browser(string $ua): ?string
    {
        // Order matters: Edge, Opera and Samsung all also say "Chrome", and
        // Chrome also says "Safari".
        $patterns = [
            'Edge'             => '/Edg(?:e|A|iOS)?\/(\d+)/',
            'Opera'            => '/(?:OPR|OPiOS)\/(\d+)/',
            'Samsung Internet' => '/SamsungBrowser\/(\d+)/',
            'Firefox'          => '/(?:Firefox|FxiOS)\/(\d+)/',
            'Chrome'           => '/(?:Chrome|CriOS)\/(\d+)/',
            'Safari'           => '/Version\/(\d+)(?:\.\d+)*.*Safari\//',
        ];

        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $ua, $m)) {
                return "{$name} {$m[1]}";
            }
        }

        return null;
    }

    private static function normalisePlatformHint(string $hint): ?string
    {
        return match (strtolower(trim($hint))) {
            'macos'     => 'Mac',
            'windows'   => 'Windows',
            'android'   => 'Android',
            'chrome os', 'chromeos' => 'ChromeOS',
            'linux'     => 'Linux',
            'ios'       => 'iPhone',
            default     => null,
        };
    }
}
