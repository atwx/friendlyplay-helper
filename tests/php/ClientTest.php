<?php

namespace Atwx\FriendlyPlay\Tests;

use Atwx\FriendlyPlay\Client;
use Atwx\FriendlyPlay\FriendlyPlay;
use Atwx\FriendlyPlay\FriendlyPlayException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class ClientTest extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    private function client(Response ...$responses): Client
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new Client('https://fp.example', 'gk_test', 'gs_secret', new HttpClient(['handler' => $stack]));
    }

    private function json(int $status, array $data, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($data));
    }

    public function testSignatureMatchesCanonicalString(): void
    {
        $body = '{"player":"user-42","value":1250}';
        $canonical = "POST\n/api/game/v1/leaderboards/highscore/entries\n1763474400\nabcdefabcdefabcd\n"
            . hash('sha256', $body);

        $this->assertSame(
            hash_hmac('sha256', $canonical, 'gs_secret'),
            Client::sign(
                'gs_secret',
                'post',
                '/api/game/v1/leaderboards/highscore/entries',
                1763474400,
                'abcdefabcdefabcd',
                $body
            )
        );
    }

    public function testSignedRequestSendsHeadersAndSignedBody(): void
    {
        $client = $this->client($this->json(201, ['kept' => true, 'entry' => ['rank' => 1]]));
        $result = $client->request(
            'POST',
            '/leaderboards/highscore/entries',
            ['player' => 'user-42', 'value' => 1250],
            ['x' => 1]
        );

        $this->assertTrue($result['kept']);
        $request = $this->history[0]['request'];
        $this->assertSame('/api/game/v1/leaderboards/highscore/entries', $request->getUri()->getPath());
        $this->assertSame('x=1', $request->getUri()->getQuery());
        $this->assertSame('gk_test', $request->getHeaderLine('X-FP-Access'));
        $this->assertSame(32, strlen($request->getHeaderLine('X-FP-Nonce')));

        $body = (string) $request->getBody();
        $this->assertSame('{"player":"user-42","value":1250}', $body);
        $this->assertSame(
            Client::sign(
                'gs_secret',
                'POST',
                '/api/game/v1/leaderboards/highscore/entries',
                (int) $request->getHeaderLine('X-FP-Timestamp'),
                $request->getHeaderLine('X-FP-Nonce'),
                $body
            ),
            $request->getHeaderLine('X-FP-Signature')
        );
    }

    public function testGetRequestSignsEmptyBody(): void
    {
        $client = $this->client($this->json(200, ['game' => []]));
        $client->request('GET', '/game');

        $request = $this->history[0]['request'];
        $this->assertSame('', (string) $request->getBody());
        $this->assertSame(
            Client::sign(
                'gs_secret',
                'GET',
                '/api/game/v1/game',
                (int) $request->getHeaderLine('X-FP-Timestamp'),
                $request->getHeaderLine('X-FP-Nonce'),
                ''
            ),
            $request->getHeaderLine('X-FP-Signature')
        );
    }

    public function testErrorsBecomeExceptions(): void
    {
        $client = $this->client($this->json(429, ['error' => 'Too many requests'], ['Retry-After' => '3']));

        try {
            $client->request('GET', '/game');
            $this->fail('Exception erwartet');
        } catch (FriendlyPlayException $e) {
            $this->assertTrue($e->isRateLimited());
            $this->assertSame(3, $e->getRetryAfter());
            $this->assertSame('Too many requests', $e->getMessage());
        }
    }

    public function testSessionIsUnsignedAndCarriesAccess(): void
    {
        $client = $this->client($this->json(200, ['token' => 'fps_x']));
        $client->startSession(['player' => 'user-42', 'playerHash' => 'h']);

        $request = $this->history[0]['request'];
        $this->assertSame('', $request->getHeaderLine('X-FP-Signature'));
        $this->assertSame('{"access":"gk_test","player":"user-42","playerHash":"h"}', (string) $request->getBody());
    }

    public function testFacadeReturnsNullForUnknownPlayerAndEncodesPath(): void
    {
        $fp = new FriendlyPlay($this->client($this->json(404, ['error' => 'Unknown player'])));

        $this->assertNull($fp->Players->get('a b/c'));
        $this->assertSame('/api/game/v1/players/a%20b%2Fc', $this->history[0]['request']->getUri()->getPath());
    }

    public function testSignalSendsEmptyFieldsAsObject(): void
    {
        $fp = new FriendlyPlay($this->client($this->json(201, ['id' => 1])));
        $fp->Signals->trigger('start', [], 'user-42');

        $this->assertSame('{"fields":{},"player":"user-42"}', (string) $this->history[0]['request']->getBody());
    }

    public function testLeaderboardAddSendsValueAndPlayer(): void
    {
        $fp = new FriendlyPlay($this->client($this->json(201, ['kept' => true, 'entry' => []])));
        $result = $fp->Leaderboards->add('highscore', 1250, 'user-42');

        $this->assertTrue($result['kept']);
        $request = $this->history[0]['request'];
        $this->assertSame('/api/game/v1/leaderboards/highscore/entries', $request->getUri()->getPath());
        $this->assertSame('{"value":1250,"player":"user-42"}', (string) $request->getBody());
    }

    public function testLeaderboardAddNeedsExactlyPlayerOrName(): void
    {
        $fp = new FriendlyPlay($this->client());
        $this->expectException(\InvalidArgumentException::class);
        $fp->Leaderboards->add('highscore', 1250);
    }

    public function testPlayersAddOmitsEmptyFields(): void
    {
        $fp = new FriendlyPlay($this->client($this->json(201, ['identifier' => 'user-42', 'hash' => 'h'])));
        $fp->Players->add('user-42', 'Mia');

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('{"identifier":"user-42","name":"Mia"}', (string) $request->getBody());
    }

    public function testFailedRequestReturnsFalseAndKeepsError(): void
    {
        $fp = (new FriendlyPlay($this->client(
            $this->json(422, ['error' => 'Das Spiel hat schon einen Spieler mit dieser Kennung']),
            $this->json(201, ['identifier' => 'user-43'])
        )))->setThrowExceptions(false);

        $this->assertFalse($fp->Players->add('user-42'));
        $this->assertTrue($fp->lastError()->isInvalid());
        $this->assertSame('Das Spiel hat schon einen Spieler mit dieser Kennung', $fp->lastError()->getMessage());

        // Erfolgreiche Anfrage setzt den Fehler zurück
        $this->assertNotFalse($fp->Players->add('user-43'));
        $this->assertNull($fp->lastError());
    }

    public function testFailedListReturnsEmptyArray(): void
    {
        $fp = (new FriendlyPlay($this->client($this->json(403, ['error' => 'Forbidden']))))
            ->setThrowExceptions(false);

        $this->assertSame([], $fp->Leaderboards->all());
        $this->assertTrue($fp->lastError()->isForbidden());
    }

    public function testDeleteReturnsBool(): void
    {
        $fp = (new FriendlyPlay($this->client(
            $this->json(200, ['deleted' => true]),
            $this->json(500, [])
        )))->setThrowExceptions(false);

        $this->assertTrue($fp->Players->delete('user-42'));
        $this->assertFalse($fp->Players->delete('user-42'));
    }

    public function testThrowExceptionsCanBeEnabled(): void
    {
        $fp = (new FriendlyPlay($this->client($this->json(422, ['error' => 'Ungültig']))))
            ->setThrowExceptions(true);

        $this->expectException(FriendlyPlayException::class);
        $fp->Leaderboards->add('highscore', 'abc', 'user-42');
    }

    public function testUnknownPlayerIsNullEvenWhenThrowing(): void
    {
        $fp = (new FriendlyPlay($this->client($this->json(404, ['error' => 'Unknown player']))))
            ->setThrowExceptions(true);

        $this->assertNull($fp->Players->get('user-42'));
    }

    public function testListsArePaged(): void
    {
        $fp = new FriendlyPlay($this->client(
            $this->json(200, ['items' => [['identifier' => 'a']], 'total' => 2, 'limit' => 500, 'offset' => 0]),
            $this->json(200, ['items' => [['identifier' => 'b']], 'total' => 2, 'limit' => 500, 'offset' => 1])
        ));

        $this->assertSame(['a', 'b'], array_column($fp->Players->all(), 'identifier'));
        $this->assertSame('limit=500&offset=1', $this->history[1]['request']->getUri()->getQuery());
    }
}
