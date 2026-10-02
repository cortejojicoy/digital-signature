<?php

use Kukux\DigitalSignature\Enums\DeviceCategory;
use Kukux\DigitalSignature\Enums\DeviceType;

/*
 * The device-type catalogue (multi-app-pairing-plan.md §4.1). The agent
 * tests its own list against the same fixture, so the two can't drift.
 */

describe('device types', function () {

    it('matches the shared catalogue (from the agent repo)', function () {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/device-types.json'), true);

        expect(array_map(fn (DeviceType $t) => $t->value, DeviceType::cases()))
            ->toBe(array_column($fixture['types'], 'type'));

        foreach ($fixture['types'] as ['type' => $type, 'category' => $category]) {
            expect(DeviceType::from($type)->category())->toBe(DeviceCategory::from($category));
        }
    });

    it('labels every type and gives it an icon', function () {
        foreach (DeviceType::cases() as $type) {
            expect($type->label())->not->toBe('')
                ->and($type->icon())->toStartWith('heroicon-o-');
        }
    });

    it('reads the coarse types browser devices store', function () {
        expect(DeviceType::fromStored('mobile'))->toBe(DeviceType::Phone)
            ->and(DeviceType::fromStored('tablet'))->toBe(DeviceType::Tablet)
            ->and(DeviceType::fromStored('desktop'))->toBe(DeviceType::Desktop)
            ->and(DeviceType::fromStored('unknown'))->toBe(DeviceType::Other)
            ->and(DeviceType::fromStored(null))->toBe(DeviceType::Other)
            ->and(DeviceType::fromStored('toaster'))->toBe(DeviceType::Other);
    });

    it('falls back to the form factor for agents from before device types', function () {
        expect(DeviceType::fromAgent('mac_mini', 'desktop'))->toBe(DeviceType::MacMini)
            ->and(DeviceType::fromAgent(null, 'laptop'))->toBe(DeviceType::Laptop)
            ->and(DeviceType::fromAgent('bogus', 'desktop'))->toBe(DeviceType::Desktop)
            ->and(DeviceType::fromAgent(null, 'unknown'))->toBe(DeviceType::Other)
            ->and(DeviceType::fromAgent(['macbook'], null))->toBe(DeviceType::Other);
    });

    it('groups every type by category for the confirm select', function () {
        $grouped = DeviceType::grouped();

        expect(array_keys($grouped))->toBe(['Computers', 'Tablets', 'Phones', 'Virtual machines', 'Other'])
            ->and(array_sum(array_map('count', $grouped)))->toBe(count(DeviceType::cases()))
            ->and($grouped['Computers']['mac_mini'])->toBe('Mac mini');
    });
});
