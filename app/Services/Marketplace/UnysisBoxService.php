<?php

namespace App\Services\Marketplace;

use App\Exceptions\Marketplace\UnysisBoxBelongsToAnotherCustomer;
use App\Exceptions\Marketplace\UnysisBoxBlocked;
use App\Models\Customer;
use App\Models\UnysisBox;
use App\Models\User;

/**
 * Auto-registration and presence tracking for UNYSIS Boxes.
 *
 * RPA-TOOL authenticates with a Customer User's credentials and reports the
 * motherboard UUID of the box it runs on (docs/adr/0002). The box record is
 * created on first sight under that user's Customer; a Team Member labels or
 * blocks it afterwards.
 */
class UnysisBoxService
{
    /**
     * How stale `last_seen_at` has to be before touch() writes again. Every
     * authenticated API request goes through the middleware, so an unthrottled
     * write would mean one UPDATE per catalogue read.
     */
    public const TOUCH_INTERVAL_SECONDS = 60;

    /**
     * Find or create the UNYSIS Box for a motherboard UUID under the signing-in
     * Customer User's Customer, and record that it was just seen.
     *
     * @throws UnysisBoxBelongsToAnotherCustomer when the UUID is registered elsewhere
     * @throws UnysisBoxBlocked when the box has been blocked
     */
    public function resolve(
        Customer $customer,
        string $motherboardUuid,
        ?string $name,
        string $ip,
        User $user,
    ): UnysisBox {
        $uuid = static::normaliseUuid($motherboardUuid);

        $box = UnysisBox::query()->where('motherboard_uuid', $uuid)->first();

        if ($box === null) {
            $box = new UnysisBox([
                'customer_id' => $customer->getKey(),
                'motherboard_uuid' => $uuid,
                'name' => $this->cleanName($name),
                'status' => UnysisBox::STATUS_PENDING,
                'first_user_id' => $user->getKey(),
            ]);
        } else {
            $this->assertUsable($box, $customer);

            // The name is a Team Member's label once one exists — never overwrite
            // it with whatever the client reports. Only fill a blank.
            if (($box->name === null || $box->name === '') && $this->cleanName($name) !== null) {
                $box->name = $this->cleanName($name);
            }
        }

        $box->last_seen_at = now();
        $box->last_ip = $ip;
        $box->save();

        return $box;
    }

    /**
     * Record that an already-authenticated box is still alive. Skips the write
     * when the box was seen less than TOUCH_INTERVAL_SECONDS ago.
     */
    public function touch(UnysisBox $box, ?string $ip): void
    {
        $lastSeen = $box->last_seen_at;

        if ($lastSeen !== null
            && $lastSeen->diffInSeconds(now(), absolute: true) < self::TOUCH_INTERVAL_SECONDS
            && (string) $box->last_ip === (string) $ip) {
            return;
        }

        $box->forceFill([
            'last_seen_at' => now(),
            'last_ip' => $ip,
        ])->save();
    }

    /**
     * Refuse a box that belongs to another Customer or has been blocked.
     *
     * @throws UnysisBoxBelongsToAnotherCustomer
     * @throws UnysisBoxBlocked
     */
    public function assertUsable(UnysisBox $box, Customer $customer): void
    {
        if ((string) $box->customer_id !== (string) $customer->getKey()) {
            throw UnysisBoxBelongsToAnotherCustomer::for($box->motherboard_uuid);
        }

        if ($box->isBlocked()) {
            throw UnysisBoxBlocked::for($box);
        }
    }

    /**
     * Motherboard UUIDs are compared case-insensitively and without surrounding
     * whitespace; the normalised form is what is stored and what the token is
     * named after.
     */
    public static function normaliseUuid(string $motherboardUuid): string
    {
        return mb_strtolower(trim($motherboardUuid));
    }

    private function cleanName(?string $name): ?string
    {
        $name = $name === null ? null : trim($name);

        return ($name === null || $name === '') ? null : mb_substr($name, 0, 255);
    }
}
