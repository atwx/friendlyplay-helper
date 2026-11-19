<?php

namespace Atwx\FriendlyPlay\Resources;

use Atwx\FriendlyPlay\Client;
use Atwx\FriendlyPlay\FriendlyPlay;

/**
 * Basis der Ressourcen ($fp->Players, $fp->Leaderboards, …).
 *
 * Die Methoden werfen bei Fehlern keine Exception (außer throw_exceptions ist
 * aktiv), sondern geben false (bzw. [] bei Listen) zurück. Den Grund liefert
 * $fp->lastError().
 */
abstract class Resource
{
    public function __construct(protected readonly FriendlyPlay $friendlyPlay)
    {
    }

    protected function client(): Client
    {
        return $this->friendlyPlay->getClient();
    }

    /**
     * Signierte Anfrage; false bei Fehler.
     *
     * @param array<mixed>|null $body
     * @param array<string, scalar> $query
     * @return array<mixed>|false
     */
    protected function send(string $method, string $path, ?array $body = null, array $query = []): array|false
    {
        return $this->friendlyPlay->attempt(fn() => $this->client()->request($method, $path, $body, $query));
    }

    /**
     * Alle Seiten einer Liste ({items, total, limit, offset}); [] bei Fehler.
     *
     * @param array<string, scalar> $query
     * @return list<array<mixed>>
     */
    protected function fetchAll(string $path, array $query = []): array
    {
        return $this->friendlyPlay->attempt(function () use ($path, $query) {
            $items = [];
            $offset = 0;
            do {
                $page = $this->client()->request('GET', $path, null, $query + ['limit' => 500, 'offset' => $offset]);
                $batch = $page['items'] ?? [];
                array_push($items, ...$batch);
                $offset += count($batch);
            } while ($batch && $offset < (int) ($page['total'] ?? 0));

            return $items;
        }, []);
    }

    /**
     * Spieler-Kennung oder Name (für Einträge ohne Spieler) – genau eins davon.
     *
     * @return array<string, string>
     */
    public static function who(?string $player, ?string $name): array
    {
        if (($player === null) === ($name === null)) {
            throw new \InvalidArgumentException('Entweder $player oder $name angeben.');
        }

        return $player !== null ? ['player' => $player] : ['name' => $name];
    }
}
