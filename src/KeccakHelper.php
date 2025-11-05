<?php
declare(strict_types=1);

namespace App\Crypto;

// Load Composer autoload from common locations via an anonymous IIFE
(static function () {
    $candidates = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        dirname(__DIR__, 2) . '/vendor/autoload.php',
    ];
    foreach ($candidates as $p) {
        if (is_file($p)) { require_once $p; return; }
    }
})();

use kornrunner\Keccak;

/**
 * Exact Keccak-256 via kornrunner/keccak; demo fallback uses PHP sha3-256.
 * Note: Keccak-256 and SHA3-256 differ.
 */
function keccak256_hex(string $data): string {
    if (class_exists(Keccak::class)) {
        return Keccak::hash($data, 256);
    }
    return hash('sha3-256', $data);
}

function keccak256_bin(string $data): string {
    return hex2bin(keccak256_hex($data)) ?: '';
}
