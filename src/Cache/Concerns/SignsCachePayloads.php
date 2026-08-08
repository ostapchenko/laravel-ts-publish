<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Cache\Concerns;

use Throwable;

trait SignsCachePayloads
{
    /**
     * Serialize a cache payload, prepending an HMAC-SHA256 signature when a signing key is configured.
     *
     * @param  array<string, mixed>  $value
     */
    protected function signPayload(array $value, ?string $key): string
    {
        $serialized = serialize($value);

        if ($key !== null && $key !== '') {
            return hash_hmac('sha256', $serialized, $key).':'.$serialized;
        }

        return $serialized;
    }

    /**
     * Verify a payload's HMAC and unserialize it into a string-keyed array, or null when any check fails.
     *
     * allowed_classes: false closes the object-injection surface on an untrusted cache backend.
     *
     * @return array<string, mixed>|null
     */
    protected function readSignedPayload(string $content, ?string $key): ?array
    {
        $serialized = $this->verifySignature($content, $key);

        if ($serialized === null) {
            return null;
        }

        try {
            $data = unserialize($serialized, ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }

        $typed = [];

        foreach ($data as $k => $v) {
            if (! is_string($k)) {
                return null;
            }

            $typed[$k] = $v;
        }

        return $typed;
    }

    /**
     * Verify and strip the HMAC signature; unsigned payloads pass through, a bad or missing one yields null.
     */
    private function verifySignature(string $content, ?string $key): ?string
    {
        if ($key === null || $key === '') {
            return $content;
        }

        if (! str_contains($content, ':')) {
            return null;
        }

        [$signature, $serialized] = explode(':', $content, 2);

        if (! hash_equals($signature, hash_hmac('sha256', $serialized, $key))) {
            return null;
        }

        return $serialized;
    }
}
