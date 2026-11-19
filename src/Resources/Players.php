<?php

namespace Atwx\FriendlyPlay\Resources;

use Atwx\FriendlyPlay\Client;
use Atwx\FriendlyPlay\FriendlyPlayException;

/**
 * Spieler des Spiels: $fp->Players->add('user-42', 'Mia').
 *
 * Ein Spieler ist ein Array mit identifier, name, displayName und – nur mit
 * dem Recht „Spieler verwalten“ – hash. Ändern/Anlegen/Löschen braucht dieses Recht.
 * Bei Fehlern kommt false zurück, Details liefert $fp->lastError().
 */
class Players extends Resource
{
    /**
     * Alle Spieler, optional gefiltert (Teil von Kennung oder Name). [] bei Fehler.
     *
     * @return list<array<mixed>>
     */
    public function all(?string $search = null): array
    {
        return $this->fetchAll('/players', $search !== null ? ['q' => $search] : []);
    }

    /**
     * @return array<mixed>|null|false der Spieler, null wenn es ihn nicht gibt, false bei Fehler
     */
    public function get(string $identifier): array|null|false
    {
        return $this->friendlyPlay->attempt(function () use ($identifier) {
            try {
                return $this->client()->request('GET', $this->path($identifier));
            } catch (FriendlyPlayException $e) {
                if ($e->isNotFound()) {
                    return null;
                }
                throw $e;
            }
        });
    }

    public function exists(string $identifier): bool
    {
        return (bool) $this->get($identifier);
    }

    /**
     * Legt einen Spieler an. Ohne $hash erzeugt friendlyplay einen.
     * Gibt es die Kennung schon, kommt false (lastError()->isInvalid()).
     *
     * @return array<mixed>|false der Spieler inkl. hash
     */
    public function add(string $identifier, ?string $name = null, ?string $hash = null): array|false
    {
        return $this->send('POST', '/players', array_filter([
            'identifier' => $identifier,
            'name' => $name,
            'hash' => $hash,
        ], fn($v) => $v !== null));
    }

    /**
     * Ändert einen Spieler. Mögliche Schlüssel: identifier, name ("" entfernt
     * den Namen), hash, newHash (true = neuen Hash erzeugen).
     *
     * @param array<string, mixed> $changes
     * @return array<mixed>|false der geänderte Spieler inkl. hash
     */
    public function update(string $identifier, array $changes): array|false
    {
        return $this->send('PATCH', $this->path($identifier), $changes);
    }

    /**
     * Ändert den Anzeigenamen (null entfernt ihn).
     *
     * @return array<mixed>|false
     */
    public function rename(string $identifier, ?string $name): array|false
    {
        return $this->update($identifier, ['name' => $name ?? '']);
    }

    /**
     * Lässt friendlyplay einen neuen Hash erzeugen. Sitzungen mit dem alten
     * Hash laufen spätestens nach 30 Minuten ab.
     *
     * @return array<mixed>|false der Spieler mit neuem hash
     */
    public function newHash(string $identifier): array|false
    {
        return $this->update($identifier, ['newHash' => true]);
    }

    /** Löscht einen Spieler samt seiner Ergebnisse. */
    public function delete(string $identifier): bool
    {
        return $this->send('DELETE', $this->path($identifier)) !== false;
    }

    /**
     * Holt den Spieler oder legt ihn an. Ein abweichender Name wird aktualisiert.
     *
     * @return array<mixed>|false
     */
    public function ensure(string $identifier, ?string $name = null): array|false
    {
        $player = $this->get($identifier);
        if ($player === false) {
            return false;
        }
        if ($player === null) {
            return $this->add($identifier, $name);
        }
        if ($name !== null && ($player['name'] ?? null) !== $name) {
            return $this->rename($identifier, $name);
        }

        return $player;
    }

    private function path(string $identifier): string
    {
        return '/players/' . Client::segment($identifier);
    }
}
