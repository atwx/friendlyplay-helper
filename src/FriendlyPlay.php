<?php

namespace Atwx\FriendlyPlay;

use Atwx\FriendlyPlay\Resources\Leaderboards;
use Atwx\FriendlyPlay\Resources\Players;
use Atwx\FriendlyPlay\Resources\Sessions;
use Atwx\FriendlyPlay\Resources\Signals;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;

/**
 * Vereinfachte Schnittstelle zu friendlyplay für Silverstripe.
 *
 *     $fp = FriendlyPlay::singleton();
 *     $fp->Players->add('user-42', 'Mia');
 *     $fp->Players->get('user-42');
 *     $fp->Leaderboards->add('highscore', 1250, 'user-42');
 *     $fp->Leaderboards->ranking('highscore', 10);
 *     $fp->Signals->trigger('station-erreicht', ['station' => 3], 'user-42');
 *
 * Zugangsdaten kommen aus der Umgebung: FRIENDLYPLAY_ACCESS (gk_…),
 * FRIENDLYPLAY_CODE (gs_…) und optional FRIENDLYPLAY_URL.
 *
 * Fehler werfen standardmäßig keine Exception: die Methoden geben false
 * (Listen: []) zurück, lastError() liefert den Grund, und der Fehler wird
 * geloggt. Mit throw_exceptions: true wird stattdessen geworfen.
 */
class FriendlyPlay
{
    use Injectable;
    use Configurable;

    /** Adresse des friendlyplay-Servers, falls FRIENDLYPLAY_URL nicht gesetzt ist. */
    private static string $base_url = 'https://friendlyplay.atw.cx';

    /** Timeout je Anfrage in Sekunden. */
    private static float $timeout = 5;

    /**
     * false (Standard): Fehler werden abgefangen und geloggt, die Methoden
     * geben false bzw. [] zurück, Details liefert lastError().
     * true: Fehler werden als FriendlyPlayException geworfen.
     */
    private static bool $throw_exceptions = false;

    /** @var array<string, string> */
    private static array $dependencies = [
        'logger' => '%$' . LoggerInterface::class,
    ];

    public readonly Players $Players;
    public readonly Leaderboards $Leaderboards;
    public readonly Signals $Signals;
    public readonly Sessions $Sessions;

    /** Wird per Injector gesetzt (siehe $dependencies). */
    public ?LoggerInterface $logger = null;

    private ?Client $client;

    private ?FriendlyPlayException $lastError = null;

    private ?bool $throwExceptions = null;

    public function __construct(?Client $client = null)
    {
        $this->client = $client;
        $this->Players = new Players($this);
        $this->Leaderboards = new Leaderboards($this);
        $this->Signals = new Signals($this);
        $this->Sessions = new Sessions($this);
    }

    /** Sind Zugangs-ID und Zugangscode gesetzt? */
    public static function isConfigured(): bool
    {
        return (bool) Environment::getEnv('FRIENDLYPLAY_ACCESS') && (bool) Environment::getEnv('FRIENDLYPLAY_CODE');
    }

    /** Der signierende Low-Level-Client, z.B. für request($method, $path, $body, $query). */
    public function getClient(): Client
    {
        if ($this->client === null) {
            $access = (string) Environment::getEnv('FRIENDLYPLAY_ACCESS');
            if ($access === '') {
                throw new FriendlyPlayException('Keine Zugangs-ID (FRIENDLYPLAY_ACCESS) konfiguriert.');
            }
            $this->client = new Client(
                (string) (Environment::getEnv('FRIENDLYPLAY_URL') ?: static::config()->get('base_url')),
                $access,
                Environment::getEnv('FRIENDLYPLAY_CODE') ?: null,
                null,
                (float) static::config()->get('timeout')
            );
        }

        return $this->client;
    }

    /**
     * Fehler der letzten Anfrage (null = erfolgreich). getMessage() enthält
     * den Fehlertext von friendlyplay, getStatus() den HTTP-Status.
     */
    public function lastError(): ?FriendlyPlayException
    {
        return $this->lastError;
    }

    /** Überschreibt throw_exceptions für diese Instanz. */
    public function setThrowExceptions(bool $throw): static
    {
        $this->throwExceptions = $throw;
        return $this;
    }

    /**
     * Führt eine Anfrage aus und fängt Fehler ab: merkt sich den Fehler,
     * loggt ihn und gibt $fallback zurück (oder wirft, wenn throw_exceptions).
     *
     * @template T
     * @param callable(): T $request
     * @return T|mixed
     */
    public function attempt(callable $request, mixed $fallback = false): mixed
    {
        $this->lastError = null;
        try {
            return $request();
        } catch (FriendlyPlayException $e) {
            $this->lastError = $e;
            if ($this->throwExceptions ?? (bool) static::config()->get('throw_exceptions')) {
                throw $e;
            }
            $this->logger?->warning('friendlyplay: ' . $e->getMessage(), [
                'status' => $e->getStatus(),
                'exception' => $e,
            ]);

            return $fallback;
        }
    }

    /**
     * Das Spiel mit seinen Leaderboards und Signalen; false bei Fehler.
     *
     * @return array{game: array<mixed>, leaderboards: list<array<mixed>>, signals: list<array<mixed>>,
     *               scopes: list<string>}|false
     */
    public function game(): array|false
    {
        return $this->attempt(fn() => $this->getClient()->request('GET', '/game'));
    }

    /**
     * Mehrere Ergebnisse/Signale in einer Anfrage – am einfachsten mit
     * BatchItem::score() / BatchItem::signal() zusammenstellen. Mehr als 100
     * Einträge werden automatisch auf mehrere Anfragen verteilt.
     *
     * Fehler einzelner Einträge stehen im Ergebnis (ok: false, error) – in
     * derselben Reihenfolge wie $items. false, wenn die Anfrage scheitert.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<mixed>>|false
     */
    public function batch(array $items): array|false
    {
        return $this->attempt(function () use ($items) {
            $results = [];
            foreach (array_chunk($items, 100) as $chunk) {
                $response = $this->getClient()->request('POST', '/batch', ['items' => $chunk]);
                array_push($results, ...($response['results'] ?? []));
            }

            return $results;
        });
    }
}
