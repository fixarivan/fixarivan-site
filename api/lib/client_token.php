<?php
declare(strict_types=1);

/**
 * Криптостойкие токены для клиентских viewer-ссылок (hex, непредсказуемые).
 * 24 байта энтропии → 48 hex-символов (~192 бит).
 */
function fixarivan_generate_client_token(): string
{
    return bin2hex(random_bytes(24));
}
