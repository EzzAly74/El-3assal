<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\Otp;

use Magento\Framework\Math\Random;
use Spartrak\CustomerAuth\Model\Config;

/**
 * Generates OTP codes and proof tokens from a cryptographic source.
 *
 * Both use random_int()/Magento's Random, never mt_rand() or a time seed. An OTP
 * generated from a predictable source is not a secret at all: an attacker who
 * can guess the PRNG state does not need to brute-force anything.
 */
class CodeGenerator
{
    /**
     * 32 bytes of entropy, hex-encoded to 64 chars — matches the token_hash
     * column width when SHA-256'd and is far beyond brute-force reach, which is
     * why the token is stored as a fast digest rather than a slow hash.
     */
    private const PROOF_TOKEN_BYTES = 32;

    public function __construct(
        private readonly Config $config,
        private readonly Random $random
    ) {
    }

    /**
     * A zero-padded numeric code of the configured length.
     *
     * Numeric and fixed-length because the shopper types it into per-digit boxes
     * on a phone keypad. Leading zeros are preserved by returning a string —
     * casting an OTP to int is a classic way to turn "012345" into a 5-digit
     * code that never matches.
     */
    public function generateCode(?int $storeId = null): string
    {
        $length = $this->config->getCodeLength($storeId);
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Single-use bearer token proving a number was just verified.
     */
    public function generateProofToken(): string
    {
        return bin2hex(random_bytes(self::PROOF_TOKEN_BYTES));
    }

    /**
     * Digest for storing/looking up a proof token.
     *
     * Plain SHA-256, not a password hash: the input is already 256 bits of
     * uniform randomness, so there is no dictionary to defend against, and the
     * digest has to be cheap because it is computed on a database lookup path.
     */
    public function hashProofToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
