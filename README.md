# Silverstripe Typesense Search Module

> Hinweis: Dieses Modul ist eine Erweiterung auf Basis von Elliots Modul `elliotsawyer/silverstripe-typesense`. Die Kernfunktionalitaet wurde vollstaendig in dieses Modul integriert.

Dieses Modul kapselt die wiederverwendbaren Bausteine fuer eine sichere Typesense-Suche mit Scoped Keys, ACL-Filtern und zentralem InstantSearch-Core.

## Was das Modul liefert

- Scoped Search Keys pro Request/User inkl. Cache und TTL
- Serverseitige ACL-Filter (`SearchVisible` + `AccessibleTo`)
- Endpoint fuer Key-Refresh (`/typesense/key`) fuer JS-Retry bei abgelaufenem Key
- Trait/Extensions fuer konsistenten Dokumentbau und Link-Sanitizing
- BaseElement-Integration mit Visibility-Vererbung von der Parent-Seite
- Setup-Task fuer ACL-Felder in allen Typesense-Collections
- integriertes Typesense-Backend auf Basis von Elliot Sawyer (inkl. Live/Stage-Sync-Logik im `DocumentUpdate`)
- zentraler JS-Core: `client/javascript/instantsearch-core.js`

Hinweis: Projektspezifische Widgets/Skripte (z. B. Blog-, Event-, Defect-Suche) bleiben absichtlich im Projekt.

## Abhaengigkeiten

Dieses Modul zieht die zentralen Pakete selbst:

- `typesense/typesense-php`
- `php-http/curl-client`
- `symbiote/silverstripe-queuedjobs`
- `symbiote/silverstripe-multivaluefield`
- `symbiote/silverstripe-gridfieldextensions`

## Installation (Projekt)

1. Modul als Composer-Dependency einbinden.
2. Composer laufen lassen.

Beispiel fuer lokales Path-Repository:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "silverstripe-typesense-instantsearch",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "moritz-sauer-13/silverstripe-typesense-instantsearch": "*"
  }
}
```

Dann:

```bash
composer update moritz-sauer-13/silverstripe-typesense-instantsearch --with-all-dependencies
```

## Umgebungsvariablen

Pflicht:

- `TYPESENSE_SERVER`
- `TYPESENSE_SEARCH_KEY` (Parent Search Key, nur `documents:search`)

Optional:

- `TYPESENSE_COLLECTION_PREFIX`
- `TYPESENSE_SCOPED_KEY_TTL` (Default: `3600`)
- `TYPESENSE_SCOPED_KEY_CACHE_TTL` (Default: `3300`)

Empfehlung:

- Key-TTL immer groesser als Cache-TTL setzen.
- Parent-Key niemals clientseitig ausgeben.

## Automatische Modul-Konfiguration

Das Modul registriert automatisch:

- `ElliotSawyer\SilverstripeTypesense\DocumentUpdate` auf `SilverStripe\ORM\DataObject`
- `MoritzSauer\Instantsearch\Extensions\BaseElementSearchVisibilityExtension` auf `DNADesign\Elemental\Models\BaseElement`
- `MoritzSauer\Instantsearch\Extensions\TypesenseCollectionExtension` auf `ElliotSawyer\SilverstripeTypesense\Collection`
- Route `_typesense` auf `ElliotSawyer\SilverstripeTypesense\Typesense`
- Route `typesense/key` auf `MoritzSauer\Instantsearch\Controller\TypesenseKeyController`
- Cache `Psr\SimpleCache\CacheInterface.TypesenseCache`
- Cache `Psr\SimpleCache\CacheInterface.TypesenseScopedKeyCache`

Optional vorhanden, aber nicht automatisch aktiviert:

- `MoritzSauer\Instantsearch\Extensions\FrontendVisibilityExtension` fuer DataObjects mit eigener Sichtbarkeitssteuerung

## Indexing-Vertrag (wichtig)

Damit die ACL sauber funktioniert, muessen alle indizierten Dokumente diese Felder haben:

- `SearchVisible` (`bool`)
- `AccessibleTo` (`string[]`)

Zusatz:

- `Link` muss ohne `stage=Stage`/`stage=Live` gespeichert werden.

Das erledigen bereits:

- `MoritzSauer\Instantsearch\Objects\TypesenseDocumentBuilderTrait` fuer Pages/DataObjects
- `MoritzSauer\Instantsearch\Extensions\BaseElementSearchVisibilityExtension` fuer Elemental-Elemente

## Tasks nach Setup oder Schema-Aenderung

1. ACL-Felder im Schema sicherstellen:

```bash
php vendor/bin/sake dev/tasks/TypesenseAclSetupTask
```

2. Danach reindizieren:

```bash
php vendor/bin/sake dev/tasks/TypesenseSyncTask
```

Wenn Fields in Collections geaendert wurden, immer beide Tasks ausfuehren.

## InstantSearch auf neuer Seite integrieren

### 1) Konfiguration im HTML bereitstellen

Der JS-Core liest Konfiguration entweder vom Suchcontainer oder als Fallback von `html[data-typesense-*]`.

Minimal:

- `data-typesense-search-key`
- `data-typesense-server`
- `data-typesense-key-refresh-url`

Empfehlung: zentral auf `<html>` setzen, nur collection-spezifische Werte am Container.

### 2) JS-Abhaengigkeiten laden

Reihenfolge:

1. `typesense-instantsearch-adapter`
2. `instantsearch.js`
3. Modul-Core:
   - `moritz-sauer-13/silverstripe-typesense-instantsearch:client/javascript/instantsearch-core.js`
4. seiten-spezifisches Suchskript

### 3) Search Client aufbauen

Fuer einzelne Collection:

- `AppInstantSearch.createSearchClient(...)`

Fuer Suche ueber mehrere Collections:

- `AppInstantSearch.createUnionSearchClient(...)`

### 4) Treffer korrekt rendern

- Titel/Highlights: `AppInstantSearch.renderHighlight(...)`
- Teaser/Text: `AppInstantSearch.stripHtml(...)`
- Links: `AppInstantSearch.normalizeSearchLink(...)`

## JS-Fallback bei abgelaufenem Key

Der Core faengt Auth-Fehler (`401/403`, invalid key) ab, holt ueber `/typesense/key` einen neuen Scoped Key und wiederholt den Request einmal automatisch.

Voraussetzung:

- `data-typesense-key-refresh-url` muss gesetzt sein.

## Sicherheit und Betriebsregeln

- Parent Search Key nie an Browser ausgeben.
- Zugriff immer ueber Scoped Key.
- Request-Filter aus dem Widget sind nur zusaetzliche Einschraenkung.
  Der ACL-Filter aus dem Scoped Key bleibt immer aktiv.
- Nach Visibility-Aenderungen reindizieren.
- Fuer versionierte Inhalte auf Live-Workflow achten (die Live/Stage-Sync-Logik ist direkt im integrierten `DocumentUpdate` enthalten).

## Troubleshooting

### Fehler: `Could not find a filter field named 'SearchVisible'`

Ursache:

- Feld fehlt im Typesense-Schema.

Loesung:

1. `php vendor/bin/sake dev/tasks/TypesenseAclSetupTask`
2. `php vendor/bin/sake dev/tasks/TypesenseSyncTask`

### Trefferlink enthaelt `stage=Stage` oder `stage=Live`

Pruefen:

- Wird `normalizeSearchLink()` im Frontend verwendet?
- Wird Dokumentbau ueber Trait/Extension gemacht (sanitized `Link`)?
- Wurde nach Codeaenderung reindiziert?

### Highlight zeigt `<mark>` als Text

Pruefen:

- Rendering ueber `AppInstantSearch.renderHighlight(...)`
- Nicht ungefiltert escapen, nachdem `renderHighlight` bereits verarbeitet wurde

## Was im Projekt bleiben sollte

- Suche-UI und Templates pro Seite/Feature
- projektspezifische `query_by`, Sortierung, Widgets
- projektspezifische Business-Filter (z. B. nur DefectReport in Defect-Suche)

Das Modul bleibt damit der wiederverwendbare Kern, das Projekt steuert die Darstellung und fachliche Suche je Seite.
