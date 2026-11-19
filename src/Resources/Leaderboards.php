<?php

namespace Atwx\FriendlyPlay\Resources;

use Atwx\FriendlyPlay\Client;

/**
 * Leaderboards des Spiels: $fp->Leaderboards->add('highscore', 1250, 'user-42').
 *
 * Ein Leaderboard ist ein Array mit key, title, type (number|time),
 * better (higher|lower), keep (all|best|worst) und entryCount. Angelegt
 * werden Leaderboards in friendlyplay selbst, hier nur Einträge.
 * Bei Fehlern kommt false zurück, Details liefert $fp->lastError().
 */
class Leaderboards extends Resource
{
    /** @return list<array<mixed>> [] bei Fehler */
    public function all(): array
    {
        return $this->fetchAll('/leaderboards');
    }

    /**
     * @return array<mixed>|null|false das Leaderboard, null wenn es keins mit
     *         diesem Key gibt, false bei Fehler
     */
    public function get(string $key): array|null|false
    {
        $all = $this->all();
        if (!$all && $this->friendlyPlay->lastError()) {
            return false;
        }
        foreach ($all as $leaderboard) {
            if (($leaderboard['key'] ?? null) === $key) {
                return $leaderboard;
            }
        }

        return null;
    }

    /**
     * Rangliste, Bester zuerst.
     *
     * @return array{items: list<array<mixed>>, total: int, limit: int, offset: int}|false
     *         Einträge mit id, rank, name, player, value, display, created
     */
    public function ranking(string $key, int $limit = 10, int $offset = 0): array|false
    {
        return $this->send('GET', $this->path($key), null, ['limit' => $limit, 'offset' => $offset]);
    }

    /**
     * Trägt ein Ergebnis ein – entweder für einen Spieler ($player = Kennung)
     * oder nur mit einem Namen ($name).
     *
     * $value: Zahl; bei Zeit-Leaderboards Millisekunden oder Text wie "1:23.456".
     *
     * @return array{kept: bool, entry: array<mixed>}|false kept = false, wenn das Leaderboard
     *         nur den besten Wert je Spieler behält und dieser es nicht war
     */
    public function add(
        string $key,
        int|float|string $value,
        ?string $player = null,
        ?string $name = null
    ): array|false {
        return $this->send('POST', $this->path($key), ['value' => $value] + self::who($player, $name));
    }

    private function path(string $key): string
    {
        return '/leaderboards/' . Client::segment($key) . '/entries';
    }
}
