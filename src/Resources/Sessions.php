<?php

namespace Atwx\FriendlyPlay\Resources;

/**
 * Spieler-Sitzungen, z.B. um ein Token an den Browser zu geben, der dann im
 * Namen des Spielers selbst Ergebnisse einträgt (30 Minuten gültig).
 * Bei Fehlern kommt false zurück, Details liefert $fp->lastError().
 */
class Sessions extends Resource
{
    /**
     * @return array{token: string, expiresAt: string, scopes: list<string>, player: array<mixed>}|false
     */
    public function start(string $player, #[\SensitiveParameter] string $hash): array|false
    {
        return $this->friendlyPlay->attempt(
            fn() => $this->client()->startSession(['player' => $player, 'playerHash' => $hash])
        );
    }

    /**
     * Spieler legt sich selbst an (Recht „Spieler dürfen sich selbst anlegen“).
     * Die Antwort enthält einmalig den playerHash.
     *
     * @return array{token: string, expiresAt: string, scopes: list<string>, player: array<mixed>,
     *               playerHash: string}|false
     */
    public function register(?string $identifier = null, ?string $name = null): array|false
    {
        return $this->friendlyPlay->attempt(fn() => $this->client()->startSession(array_filter([
            'register' => true,
            'player' => $identifier,
            'name' => $name,
        ], fn($v) => $v !== null)));
    }
}
