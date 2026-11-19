<?php

namespace Atwx\FriendlyPlay;

use RuntimeException;
use Throwable;

/**
 * Fehler bei der Kommunikation mit friendlyplay.
 *
 * getStatus() liefert den HTTP-Status (0 = Server nicht erreichbar),
 * getMessage() den Fehlertext aus der Antwort ({"error": "…"}).
 */
class FriendlyPlayException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 0,
        private readonly ?int $retryAfter = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** Bei 429: Sekunden bis zum nächsten Versuch (Header Retry-After). */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /** Unbekannter Spieler bzw. Pfad. */
    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /** Ungültige Werte, unbekanntes Leaderboard/Signal oder doppelte Kennung. */
    public function isInvalid(): bool
    {
        return $this->status === 422;
    }

    /** Signatur, Zeitstempel, Nonce, Sitzung oder Zugangs-ID ungültig. */
    public function isUnauthorized(): bool
    {
        return $this->status === 401;
    }

    /** Dem Zugang fehlt das nötige Recht. */
    public function isForbidden(): bool
    {
        return $this->status === 403;
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }
}
