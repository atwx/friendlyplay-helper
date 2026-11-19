# friendlyplay-helper

Einfache Anbindung von Silverstripe 6 an [friendlyplay](https://friendlyplay.atw.cx/wiki):
Ergebnisse in Leaderboards eintragen, Ranglisten abrufen, Spieler verwalten und Signale auslösen.

```php
FriendlyPlay::singleton()->Leaderboards->add('highscore', 1250, 'player-42');
```

Das Modul spricht als Spiel-Server mit friendlyplay und signiert jede Anfrage selbst. Der
geheime Zugangscode bleibt auf dem Server und gelangt nie in den Browser.

## Einbauen in 5 Schritten

### 1. Zugang in friendlyplay anlegen

In friendlyplay das Spiel öffnen → **API-Zugang** → neuen Zugang anlegen und die nötigen Rechte
anhaken (zum Eintragen von Ergebnissen mindestens „Leaderboard-Ergebnisse eintragen“, zum
Anzeigen von Ranglisten „lesen“, für `Players` „Spieler verwalten“).

Notiere dir **Zugangs-ID** (`gk_…`) und **Zugangscode** (`gs_…`). Den Code zeigt friendlyplay
**nur einmal** an.

> Tipp: Für Entwicklung und Live getrennte Zugänge anlegen.

### 2. Modul installieren

Das Repository ist privat. Composer braucht deshalb einen SSH-Schlüssel, der bei GitHub
hinterlegt ist. Unter DDEV vorher einmal `ddev auth ssh` ausführen.

```sh
composer config repositories.friendlyplay-helper vcs git@github.com:atwx/friendlyplay-helper.git
composer require atwx/friendlyplay-helper:6.x-dev
```

### 3. Zugangsdaten in die `.env`

```sh
FRIENDLYPLAY_ACCESS="gk_…"   # Zugangs-ID
FRIENDLYPLAY_CODE="gs_…"     # Zugangscode – geheim, nie committen!
```

Optional: `FRIENDLYPLAY_URL`, falls nicht `https://friendlyplay.atw.cx` verwendet wird.

### 4. Leaderboards und Signale in friendlyplay anlegen

Leaderboards und Signale legst du in der friendlyplay-Oberfläche an, nicht im Code. Im Code
sprichst du sie über ihren **Key** an (z. B. `highscore`). Einstellungen beim Leaderboard:

- **Werte**: Zahl oder Zeit (Zeit wird in Millisekunden übertragen)
- **Reihenfolge**: mehr oder weniger ist besser
- **Ergebnisse je Spieler**: alle, nur das beste oder nur das schlechteste

### 5. Verbindung prüfen

```php
use Atwx\FriendlyPlay\FriendlyPlay;

print_r(FriendlyPlay::singleton()->game());
```

Wenn alles stimmt, kommen Titel, Leaderboards, Signale und die Rechte (`scopes`) des Zugangs zurück.
Kommt `false`, zeigt `FriendlyPlay::singleton()->lastError()->getMessage()` den Grund.
Bei Status 401 stimmen ID oder Code nicht.

## Daten an friendlyplay senden

```php
use Atwx\FriendlyPlay\FriendlyPlay;

$fp = FriendlyPlay::singleton();
```

**Spieler anlegen.** Die Kennung legst du selbst fest, z. B. aus der ID deines eigenen DataObjects:

```php
$fp->Players->add('player-42', 'Mia');      // anlegen
$fp->Players->ensure('player-42', 'Mia');   // anlegen, falls es ihn noch nicht gibt; Name abgleichen
$fp->Players->rename('player-42', 'Mia M.');
$fp->Players->delete('player-42');          // löscht auch seine Ergebnisse
```

**Ergebnis eintragen:**

```php
$fp->Leaderboards->add('highscore', 1250, 'player-42');      // für einen Spieler
$fp->Leaderboards->add('highscore', 980, name: 'Gast');      // ohne Spieler, nur mit Namen
$fp->Leaderboards->add('bestzeit', 83456, 'player-42');      // Zeit in Millisekunden (oder '1:23.456')
```

Die Antwort enthält `kept`. Der Wert ist `false`, wenn das Leaderboard nur das beste Ergebnis je
Spieler behält und das neue Ergebnis nicht besser war.

**Signal auslösen:**

```php
$fp->Signals->trigger('station-erreicht', ['station' => 3], 'player-42');
```

**Viele Einträge auf einmal** (Fehler einzelner Einträge stehen im Ergebnis, statt eine
Exception auszulösen):

```php
use Atwx\FriendlyPlay\BatchItem;

$results = $fp->batch([
    BatchItem::score('highscore', 1250, 'player-42'),
    BatchItem::signal('runde-beendet', ['runde' => 3], 'player-42'),
]);
```

## Daten von friendlyplay abrufen

```php
$fp->Leaderboards->ranking('highscore', 10);  // Top 10: items[] mit rank, name, value, display
$fp->Leaderboards->get('highscore');          // Einstellungen + entryCount
$fp->Leaderboards->all();

$fp->Players->get('player-42');               // Spieler oder null
$fp->Players->all('mia');                     // alle, optional gefiltert

$fp->Signals->all();                          // Signale mit ihren Feldern
$fp->game();                                  // alles zum Spiel auf einmal
```

Alle Rückgaben sind einfache Arrays (das JSON von friendlyplay).

## Hat es geklappt?

Die Methoden werfen keine Exceptions. Das eigene Spiel läuft also weiter, auch wenn friendlyplay
einmal nicht erreichbar ist. Am Rückgabewert siehst du direkt, ob die Anfrage durchging:

| Rückgabe | Bedeutung |
|---|---|
| Array mit Daten | erfolgreich |
| `false` | Fehler (bei `delete()`: `true` / `false`) |
| `null` | nur bei `get()`: gibt es nicht |
| `[]` | bei `all()`: leer **oder** Fehler, siehe `lastError()` |

```php
if ($fp->Leaderboards->add('highscore', $score, 'player-' . $player->ID)) {
    // eingetragen
} else {
    $error = $fp->lastError();
    $error->getMessage();     // Fehlertext von friendlyplay
    $error->getStatus();      // HTTP-Status; 0 = nicht erreichbar
    $error->isInvalid();      // 422: ungültiger Wert, unbekannter Key, Kennung schon vergeben
    $error->isForbidden();    // 403: dem Zugang fehlt das Recht
    $error->getRetryAfter();  // 429: Sekunden bis zum nächsten Versuch
}
```

Jeder Fehler wird zusätzlich ins Silverstripe-Log geschrieben (Level `warning`).

Lieber mit Exceptions arbeiten? Dann einschalten, und alle Fehler werden als
`FriendlyPlayException` geworfen:

```yaml
Atwx\FriendlyPlay\FriendlyPlay:
  throw_exceptions: true
```

Fehlende Zugangsdaten in der `.env` sind ebenfalls ein Fehler (`false`). Mit
`FriendlyPlay::isConfigured()` kannst du vorher prüfen, ob sie gesetzt sind.

## Für Fortgeschrittene

- **Sitzungen für den Browser:** `$fp->Sessions->start('player-42', $hash)` liefert ein Token,
  30 Minuten gültig. Damit kann der Browser im Namen des Spielers selbst Ergebnisse eintragen.
- **Low-Level:** `$fp->getClient()->request('GET', '/leaderboards')` erlaubt beliebige
  signierte Aufrufe der Spiel-API.
- **Konfiguration per YAML:** `Atwx\FriendlyPlay\FriendlyPlay.base_url`, `.timeout` (Sekunden)
  und `.throw_exceptions`.
- Der Low-Level-Client wirft immer `FriendlyPlayException`.
- API-Referenz: [friendlyplay-Wiki → Für Entwickler](https://friendlyplay.atw.cx/wiki/entwickler)

## Tests

```sh
vendor/bin/phpunit
```

## Lizenz

BSD-3-Clause, siehe [LICENSE](LICENSE).
