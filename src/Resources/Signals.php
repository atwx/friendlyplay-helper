<?php

namespace Atwx\FriendlyPlay\Resources;

use Atwx\FriendlyPlay\Client;
use DateTimeInterface;

/**
 * Signale des Spiels: $fp->Signals->trigger('station-erreicht', ['station' => 3], 'user-42').
 * Bei Fehlern kommt false zurück, Details liefert $fp->lastError().
 */
class Signals extends Resource
{
    /**
     * Die Signale des Spiels mit ihren Feldern (key, title, fields). [] bei Fehler.
     *
     * @return list<array<mixed>>
     */
    public function all(): array
    {
        $game = $this->friendlyPlay->game();

        return $game ? ($game['signals'] ?? []) : [];
    }

    /**
     * Löst ein Signal aus. Pflichtfelder müssen dabei sein, unbekannte Felder
     * werden abgelehnt (false, lastError()->isInvalid(); es wird nichts gespeichert).
     *
     * @param array<string, mixed> $fields Feld-Key => Wert
     * @return array<mixed>|false das ausgelöste Signal (id, signal, at, player, fields)
     */
    public function trigger(
        string $key,
        array $fields = [],
        ?string $player = null,
        ?DateTimeInterface $at = null
    ): array|false {
        $body = ['fields' => (object) $fields];
        if ($player !== null) {
            $body['player'] = $player;
        }
        if ($at !== null) {
            $body['at'] = $at->format(DATE_ATOM);
        }

        return $this->send('POST', '/signals/' . Client::segment($key), $body);
    }
}
