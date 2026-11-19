<?php

namespace Atwx\FriendlyPlay;

use Atwx\FriendlyPlay\Resources\Resource;
use DateTimeInterface;

/**
 * Baut Einträge für FriendlyPlay::batch().
 *
 *     $fp->batch([
 *         BatchItem::score('highscore', 1250, 'user-42'),
 *         BatchItem::signal('runde-beendet', ['runde' => 3], 'user-42'),
 *     ]);
 */
class BatchItem
{
    /**
     * @return array<string, mixed>
     */
    public static function score(
        string $leaderboard,
        int|float|string $value,
        ?string $player = null,
        ?string $name = null
    ): array {
        return ['leaderboard' => $leaderboard, 'value' => $value] + Resource::who($player, $name);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function signal(
        string $key,
        array $fields = [],
        ?string $player = null,
        ?DateTimeInterface $at = null
    ): array {
        $item = ['signal' => $key, 'fields' => (object) $fields];
        if ($player !== null) {
            $item['player'] = $player;
        }
        if ($at !== null) {
            $item['at'] = $at->format(DATE_ATOM);
        }

        return $item;
    }
}
