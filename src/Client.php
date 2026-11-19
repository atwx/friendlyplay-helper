<?php

namespace Atwx\FriendlyPlay;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

/**
 * Low-Level-Client für die Spiel-API von friendlyplay (/api/game/v1).
 *
 * Kümmert sich um das Signieren der Server-Anfragen (HMAC-SHA256 mit dem
 * Zugangscode), JSON und Fehlerbehandlung. Für den Alltag ist die Fassade
 * FriendlyPlay gedacht; dieser Client steht für alles andere zur Verfügung.
 *
 * @see https://friendlyplay.atw.cx/wiki/entwickler/spiel-server
 */
class Client
{
    public const API_PATH = '/api/game/v1';

    private ClientInterface $http;

    /**
     * @param string $baseUrl Adresse des friendlyplay-Servers, z.B. https://friendlyplay.atw.cx
     * @param string $access Zugangs-ID (gk_…)
     * @param string|null $code Zugangscode (gs_…); ohne Code sind nur Sitzungs-Anfragen möglich
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $access,
        #[\SensitiveParameter] private readonly ?string $code = null,
        ?ClientInterface $http = null,
        float $timeout = 5.0
    ) {
        $this->http = $http ?? new HttpClient(['timeout' => $timeout]);
    }

    public function getAccess(): string
    {
        return $this->access;
    }

    /**
     * Signierte Server-Anfrage.
     *
     * @param string $path Pfad unterhalb von /api/game/v1, z.B. "/players/user-42"
     * @param array<mixed>|null $body JSON-Body (null = keiner)
     * @param array<string, scalar> $query Query-Parameter (gehen nicht in die Signatur ein)
     * @return array<mixed>
     */
    public function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        if (!$this->code) {
            throw new FriendlyPlayException('Kein Zugangscode (FRIENDLYPLAY_CODE) konfiguriert.');
        }

        $method = strtoupper($method);
        $fullPath = self::API_PATH . $path;
        $raw = $body === null ? '' : self::encode($body);
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));

        $headers = [
            'X-FP-Access' => $this->access,
            'X-FP-Timestamp' => (string) $timestamp,
            'X-FP-Nonce' => $nonce,
            'X-FP-Signature' => self::sign($this->code, $method, $fullPath, $timestamp, $nonce, $raw),
        ];

        return $this->send($method, $fullPath, $raw, $query, $headers);
    }

    /**
     * Anfrage im Namen eines Spielers mit einem Sitzungs-Token (fps_…).
     *
     * @param array<mixed>|null $body
     * @param array<string, scalar> $query
     * @return array<mixed>
     */
    public function sessionRequest(
        #[\SensitiveParameter] string $token,
        string $method,
        string $path,
        ?array $body = null,
        array $query = []
    ): array {
        $raw = $body === null ? '' : self::encode($body);
        $headers = ['Authorization' => 'Bearer ' . $token];

        return $this->send(strtoupper($method), self::API_PATH . $path, $raw, $query, $headers);
    }

    /**
     * Startet eine Spieler-Sitzung (POST /session, unsigniert).
     *
     * @param array<string, mixed> $data player/playerHash bzw. register/name
     * @return array<mixed> token, expiresAt, scopes, player (und playerHash bei register)
     */
    public function startSession(array $data): array
    {
        $raw = self::encode(['access' => $this->access] + $data);

        return $this->send('POST', self::API_PATH . '/session', $raw, [], []);
    }

    /**
     * Berechnet die Signatur einer Server-Anfrage.
     *
     * Kanonischer Text (Zeilen mit \n getrennt): Methode, voller Pfad ohne
     * Query, Zeitstempel, Nonce, SHA-256 (hex) des rohen Bodys.
     */
    public static function sign(
        #[\SensitiveParameter] string $code,
        string $method,
        string $fullPath,
        int $timestamp,
        string $nonce,
        string $body
    ): string {
        $canonical = implode("\n", [
            strtoupper($method),
            $fullPath,
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);

        return hash_hmac('sha256', $canonical, $code);
    }

    /** Kodiert einen Pfadbestandteil (z.B. Spielerkennung oder Leaderboard-Key). */
    public static function segment(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * @param array<mixed> $data
     */
    public static function encode(array $data): string
    {
        try {
            return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new FriendlyPlayException(
                'Daten lassen sich nicht als JSON kodieren: ' . $e->getMessage(),
                0,
                null,
                $e
            );
        }
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     * @return array<mixed>
     */
    private function send(string $method, string $fullPath, string $raw, array $query, array $headers): array
    {
        $headers['Accept'] = 'application/json';
        $options = ['headers' => $headers, 'http_errors' => false];
        if ($raw !== '') {
            $options['headers']['Content-Type'] = 'application/json';
            // Genau diese Bytes wurden signiert – nicht von Guzzle neu kodieren lassen
            $options['body'] = $raw;
        }
        if ($query) {
            $options['query'] = $query;
        }

        try {
            $response = $this->http->request($method, rtrim($this->baseUrl, '/') . $fullPath, $options);
        } catch (GuzzleException $e) {
            throw new FriendlyPlayException('friendlyplay nicht erreichbar: ' . $e->getMessage(), 0, null, $e);
        }

        $status = $response->getStatusCode();
        $content = (string) $response->getBody();
        $data = $content === '' ? [] : json_decode($content, true);

        if ($status >= 400) {
            $message = is_array($data) && isset($data['error']) ? (string) $data['error'] : "HTTP $status";
            $retryAfter = $response->getHeaderLine('Retry-After');
            throw new FriendlyPlayException($message, $status, $retryAfter !== '' ? (int) $retryAfter : null);
        }
        if (!is_array($data)) {
            throw new FriendlyPlayException("Unerwartete Antwort von friendlyplay (HTTP $status).", $status);
        }

        return $data;
    }
}
