<?php

namespace Kukux\DigitalSignature\Enums;

/**
 * What a signing device is: "MacBook Pro", "All-in-one PC", "Virtual machine".
 *
 * Reported by the desktop agent (digital-signature-agent src/main/device-type.ts)
 * and correctable by the owner when they confirm a pairing. It is a label for
 * people, never a security signal: assurance comes from the key's protection
 * and attestation. The one exception is policy on the *detected* type, which
 * the owner cannot override (signature.devices.agent.blocked_device_types).
 *
 * Same values, in the same order, as tests/Fixtures/device-types.json, which
 * the agent tests against too. Phones, tablets and Chromebooks are accepted
 * but nothing reports them yet: they wait for a mobile app.
 */
enum DeviceType: string
{
    // Computers: Apple
    case MacBook = 'macbook';
    case MacBookAir = 'macbook_air';
    case MacBookPro = 'macbook_pro';
    case IMac = 'imac';
    case MacMini = 'mac_mini';
    case MacStudio = 'mac_studio';
    case MacPro = 'mac_pro';

    // Computers: PC
    case Laptop = 'laptop';
    case Convertible = 'convertible';
    case Desktop = 'desktop';
    case AllInOne = 'all_in_one';
    case MiniPc = 'mini_pc';
    case Server = 'server';
    case Chromebook = 'chromebook';

    // Tablets
    case Tablet = 'tablet';
    case IPad = 'ipad';
    case AndroidTablet = 'android_tablet';

    // Phones
    case IPhone = 'iphone';
    case Android = 'android';
    case Phone = 'phone';

    // Virtual and fallback
    case VirtualMachine = 'virtual_machine';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::MacBook        => 'MacBook',
            self::MacBookAir     => 'MacBook Air',
            self::MacBookPro     => 'MacBook Pro',
            self::IMac           => 'iMac',
            self::MacMini        => 'Mac mini',
            self::MacStudio      => 'Mac Studio',
            self::MacPro         => 'Mac Pro',
            self::Laptop         => 'Computer laptop',
            self::Convertible    => '2-in-1 laptop',
            self::Desktop        => 'Computer desktop',
            self::AllInOne       => 'All-in-one PC',
            self::MiniPc         => 'Mini PC',
            self::Server         => 'Server',
            self::Chromebook     => 'Chromebook',
            self::Tablet         => 'Tablet',
            self::IPad           => 'iPad',
            self::AndroidTablet  => 'Android tablet',
            self::IPhone         => 'iPhone',
            self::Android        => 'Android phone',
            self::Phone          => 'Other phone',
            self::VirtualMachine => 'Virtual machine',
            self::Other          => 'Other device',
        };
    }

    public function category(): DeviceCategory
    {
        return match ($this) {
            self::Tablet, self::IPad, self::AndroidTablet  => DeviceCategory::Tablet,
            self::IPhone, self::Android, self::Phone       => DeviceCategory::Phone,
            self::VirtualMachine                           => DeviceCategory::Virtual,
            self::Other                                    => DeviceCategory::Other,
            default                                        => DeviceCategory::Computer,
        };
    }

    /** Heroicon name. */
    public function icon(): string
    {
        return match ($this) {
            self::MacMini, self::MacStudio, self::MiniPc => 'heroicon-o-cpu-chip',
            self::Server                                  => 'heroicon-o-server-stack',
            self::VirtualMachine                          => 'heroicon-o-cloud',
            self::Other                                   => 'heroicon-o-question-mark-circle',
            default => match ($this->category()) {
                DeviceCategory::Tablet => 'heroicon-o-device-tablet',
                DeviceCategory::Phone  => 'heroicon-o-device-phone-mobile',
                default                => 'heroicon-o-computer-desktop',
            },
        };
    }

    /**
     * The type of a stored device row. Browser devices (DeviceDescriptor)
     * write the coarser `desktop | mobile | tablet | unknown`; `mobile` and
     * `unknown` aren't catalogue values, so they map here.
     */
    public static function fromStored(?string $value): self
    {
        return match ($value) {
            'mobile' => self::Phone,
            null, '', 'unknown' => self::Other,
            default  => self::tryFrom($value) ?? self::Other,
        };
    }

    /**
     * The type an agent claimed. Agents from before device types send none,
     * only the battery-based form factor.
     */
    public static function fromAgent(mixed $value, ?string $formFactor = null): self
    {
        if (is_string($value) && ($type = self::tryFrom($value)) !== null) {
            return $type;
        }

        return match ($formFactor) {
            'laptop'  => self::Laptop,
            'desktop' => self::Desktop,
            default   => self::Other,
        };
    }

    /**
     * Every type grouped by category, for a select: `['Computers' => ['macbook' => 'MacBook', …], …]`.
     *
     * @return array<string, array<string, string>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::cases() as $type) {
            $groups[$type->category()->label()][$type->value] = $type->label();
        }

        return $groups;
    }
}
