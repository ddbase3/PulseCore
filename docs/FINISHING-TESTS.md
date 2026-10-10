# PulseCore 0.3.1: Finishing-Testprotokoll

Gepruefter Ausgangsstand: der freigegebene 48-Level-Patch
`PulseCore-0.3.0-step3-unzip-o.zip`, auf 0.2.2 und dessen Vorgaengern.
Der alternative 72-Level-Entwurf wurde nicht als Ausgangsbasis verwendet.

## Erfolgreich ausgefuehrt

| Pruefung | Ergebnis |
| --- | --- |
| `php -d ffi.enable=1 test/run.php` | 247 Assertions bestanden |
| `php -d ffi.enable=1 test/campaign.php` | 40 Assertions bestanden |
| `php -d ffi.enable=1 test/atomic_upgrade.php` | 3 Assertions bestanden |
| `npm test` | 315 Tests bestanden: 292 bestehende Regeln/Paritaet/Kampagne, 23 Audio-/Renderer-Tests |
| `python test/Browser/finishing_test.py` | 39 Browser-Assertions bestanden |
| PHP-Syntax in src/, tpl/ und test/ | bestanden |
| JavaScript-Syntax der produktiven ES-Module | bestanden |

Alle 48 Referenzloesungen werden von den bestehenden PHP-/JavaScript- und
Paritaetspruefungen abgedeckt. Im neuen gezielten Browserlauf wurde Mission 4
per Touch und LIVE-Direkttaste bis zum PHP-bestaetigten Abschluss gespielt.
Dies ist keine Behauptung eines erneuten Browserdurchlaufs aller 48 Missionen.

## Audio

Die Browserpruefung verwendet einen nativen AudioContext und einen AnalyserNode
am echten Effekt-Master. Nach expliziter Touch-Aktivierung wurde ein Signal mit
Spitzenamplitude ca. 0,0891 gemessen. Nach Ausschalten blieb das Ausgangssignal
unter 0,001. Lautstaerke, context.suspend(), Wiederaufnahme per Geste, Suspendieren
versteckter Displays und context.close() beim Entfernen wurden geprueft.
Die Audio-Unit-Tests pruefen zusaetzlich verweigerte/unbestaetigte Aktivierung,
Unterbrechungszustaende, Zeitlimit, Stimmenlimit und Abbau waehrend Aktivierung.

Es wurde nicht ueber reale Lautsprecher abgehoert. Eine Systemstummschaltung,
falsche Audioausgabe oder ein physischer iOS-/Android-Unterbrechungsfall ist
nicht durch diese Messung bestaetigt. Der Zustand `interrupted` wird im
Unit-Test simuliert; die Browserpruefung suspendiert einen realen AudioContext.

## Mobile Bedienung und Effekte

Chromium wurde mit Touchscreen, mobilen Viewports und nativen emulierten
Touch-Ereignissen betrieben. Geprueft wurden Tippen, langes Halten ohne
Doppelausloesung, Rueckwaertsdrehen, LIVE-Tasten, Touch-Scrollen ohne Spielzug,
320/360/390 Pixel Breite, Querformat und ein schmaler Desktop-Container.
Die Drehtasten haben mindestens 48 x 48 CSS-Pixel; dies ist keine Aussage,
dass jede einzelne Zelle des 7-x-7-Bretts auf kleinen Telefonen so gross ist.

Reduzierte Bewegung unterbindet dekorative Partikel und Ringe. Effektbudgets,
Ablauf, Pause, Levelwechsel und Freigabe der Renderer-Ressourcen wurden geprueft.

## Unveraendertheit

Die Leveldatei, beide Spielkerne und die bestehenden Repository-, Service-,
API-, Output- und Migrationsdateien wurden byteweise mit dem Ausgangsstand
verglichen und blieben unveraendert. Im produktiven PHP-Code aendert sich nur
die Asset-Versionsnummer des bestehenden Displays; die Vorlage erhaelt die
Bedienelemente. Keine neue Tabelle, keine Migration, keine Aenderung an Base3Ilias.

## Testumgebung und Grenzen

Die PHP-Tests nutzen den vorhandenen SQLite/FFI-Testadapter, der Transaktionen
verweigert. Es ist kein eigener MySQL-/MariaDB- oder ILIAS-Laufzeittest.
Der Browser darf in dieser Umgebung keine Loopback-URL normal navigieren.
Deshalb werden die echten Vorlagen und Module offline geladen; die unveraenderten
Request-Bodies gehen ueber die vorhandene Testbruecke an den echten lokalen
PHP-Endpunkt. Die originalen PNG-Bytes liegen pro Testseite in Blob-URLs,
nicht wiederholt als sehr grosse Inline-Strings in Bootstrap-Skripten.
Produktive Auslieferung, Host-CSP und echtes Netzwerkverhalten sind dadurch
nicht geprueft. Der produktive Asset-Ladeweg bleibt unveraendert.

Der alte synchrone Komplettlauf `test/Browser/browser_test.py` lief in dieser
Umgebung in ein Zeitlimit und wird fuer diese Version nicht als bestanden
gezaehlt. Die oben ausgewiesenen 39 Pruefungen stammen ausschliesslich aus dem
erfolgreich abgeschlossenen asynchronen Finishing-Lauf.

Es gab keinen Test auf physischen Mobilgeraeten, unter Safari/Firefox oder
gegen die konkrete ILIAS-Installation. Native Vollbildumschaltung wurde in
diesem Finishing-Lauf nicht erneut geprueft; deren Implementierung blieb gleich.

## Einzelne erfolgreiche Browserpruefungen

1. No autoplay or AudioContext before explicit activation
2. Touch activation confirms a running native AudioContext
3. Native synthesized test signal is nonzero and below clipping (peak 0.0891)
4. Volume range controls the real audio master
5. Volume percentage is visible
6. A suspended native context is visible rather than falsely reported as active
7. Explicit touch resumes a suspended native context
8. Mute stops scheduled effects and the measured output is silent
9. One touchscreen tap produces exactly one server-confirmed turn
10. Board-local reverse control restores orientation on touch
11. Reverse control does not create duplicate actions
12. Rotation dock is immediately below the board
13. Primary touch rotation target is at least 48 by 48 CSS pixels
14. Scrolling across a module does not submit a turn
15. Native touch scrolling remains available on the board
16. Long touch produces one action, not a second release click
17. Long touch turns backward without wasting actions on mobile
18. LIVE shortcut is built from the actual level module
19. LIVE shortcut is disabled before launch
20. Mission 4 completes on touch using the LIVE shortcut and PHP validation
21. Finished attempts disable their LIVE shortcut
22. No page-level horizontal overflow at 320px
23. Launch remains available at 320px
24. No page-level horizontal overflow at 360px
25. Launch remains available at 360px
26. No page-level horizontal overflow at 390px
27. Launch remains available at 390px
28. Landscape retains the responsive single display
29. Reduced-motion preference suppresses spark and ring feedback
30. Hidden AJAX display does not advance the simulation
31. Hidden display stops audio voices and animation frames
32. Returning to a hidden tab requires explicit game/audio resume
33. One resume gesture restarts the game and native audio
34. AJAX removal closes the native audio context and releases voices
35. Recreated AJAX fragment restores confirmed progress and starts audio off
36. Desktop click and Q still perform one action each
37. Fusion generates visible energy rings in the real Canvas renderer
38. Container-based layout also fits a narrow embedded desktop tab
39. No uncaught browser JavaScript errors
