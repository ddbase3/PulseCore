# PULSE//CORE 0.3.0

Schritt 3: 48 Missionen, sechs Sektoren, Erfahrungspunkte, Ränge und
Auszeichnungen. Ein einziges BASE3-Display: **`pulsecoredisplay`**.

## Update von 0.2.2

Den ZIP-Patch im Verzeichnis oberhalb des bestehenden `PulseCore/`-Ordners
entpacken:

```sh
unzip -o PulseCore-0.3.0-step3-unzip-o.zip
```

Danach die Classmap über den üblichen Projektmechanismus aktualisieren
und das Display neu laden. Die Einbindung, der technische Displayname und
optionale Displaydaten wie `{"level": 1}` bleiben bestehen.

**Keine neue Datenbankmigration.** Die funktionierende Snapshot-Speicherung
aus 0.2.1 bleibt unverändert. Der Patch ersetzt weder Base3Ilias noch dessen
Datenbankadapter, Session oder Routing. Es gibt keinen Aufruf von
`beginTransaction()`, `commit()` oder `rollback()` im Spielbetrieb.
Die vorhandenen Migrationen 001 und 002 werden nicht geändert.

Die sechs Bilder aus 0.2.2 werden weiterverwendet. Der Patch setzt deshalb
das installierte Artwork-Update 0.2.2 voraus. Es werden keine neuen Bilder
generiert und keine Bilder von externen Diensten nachgeladen. Alle URLs
werden weiterhin durch `IAssetResolver` erzeugt.

## Bestehende Spielstände

Die Regeldaten und IDs der sechs bisherigen Missionen bleiben identisch.
Ihre Hashes, Bestwerte und der aktuelle Versuch bleiben gültig. Die
Engineversion bleibt 1. Das größere Kampagnenmenü ändert keinen
laufenden Schaltkreis.

Wer zuvor alle sechs Missionen beendet hat, sieht nun 6 von 48 Abschlüssen.
Die alten Ergebnisse zählen unmittelbar für XP und Auszeichnungen.
Mission 7 ist die nächste offene Aufgabe; ein bestehender Spielstand wird
nicht gelöscht, neu begonnen oder stillschweigend konvertiert.

## Die Kampagne

| Sektor | Missionen | Schwerpunkt | Bestehendes Artwork |
| --- | --- | --- | --- |
| Zündung | 1 bis 8 | Bekanntes Training, Impulsfolgen und drei Relais | Blauer Energienexus |
| Spektrum | 9 bis 16 | Getrennte Energien, Fusion, mehrere Mischer | Violette Prismenstation |
| Flux | 17 bis 24 | LIVE-Verzweigungen und wiederholtes Umschalten | Orange Plasmastation |
| Resonanz | 25 bis 32 | HOLD, Weglängen und zeitliche Paarung | Türkiser Resonanzring |
| Störfeld | 33 bis 40 | Verriegelte Module, Sackgassen und begrenzte Laufzeit | Gebrochener goldener Ring |
| Singularität | 41 bis 48 | Mehrfachladungen, Fusionen, Puffer und Schaltketten | Station am schwarzen Loch |

Sektor 1 ist von Beginn an offen. Innerhalb eines offenen Sektors sind alle
acht Missionen wählbar. Der nächste Sektor wird erst freigeschaltet,
wenn alle Missionen des vorherigen Sektors bestanden sind. Vorschaukarten
zeigen auch gesperrte Sektoren, erlauben aber keinen Start. Das PHP-Backend
prüft die Freischaltung unabhängig von der Benutzeroberfläche.

Jede Mission besitzt eine feste Definition mit nachvollziehbarer Lösung.
Es gibt keinen Laufzeit-Zufallsgenerator, der möglicherweise unlösbare
Spielfelder erzeugt. Referenzlösungen liegen ausschließlich unter
`test/Engine/solutions.json` und werden nicht an das Display ausgeliefert.

Nach einem Level folgt die nächste noch offene Mission desselben Sektors.
Nach dem letzten fehlenden Sektorlevel erscheint der Sektorabschluss mit
Sternen, Bonus und Zugang zum folgenden Sektor. Nach allen 48 Missionen
erscheint der Kampagnenabschluss. Es gibt keinen automatischen Neustart der
letzten Mission. Gezieltes Wiederholen bleibt möglich.

## XP, Ränge und Erfolge

XP werden aus den bestätigten Bestleistungen berechnet:

* 100 XP für jede unterschiedliche bestandene Mission.
* 25 XP für jeden auf dieser Mission erreichten Stern.
* 250 XP für jeden vollständig abgeschlossenen Sektor.

Eine Dreisterne-Mission ergibt damit 175 XP. Eine Verbesserung von zwei auf
drei Sterne gibt weitere 25 XP. Eine gleich gute oder schlechtere Wiederholung
gibt keine weiteren XP und senkt weder XP noch Bestwerte. Der Abschluss
aller Missionen mit drei Sternen ergibt 9.900 XP.

Neun Ränge reichen vom Kadetten bis zum Meister des Netzwerks. Die Grenzen
liegen bei 0, 300, 800, 1.600, 2.800, 4.500, 6.500, 8.500 und 9.900 XP.
Der Balken oben zeigt den Fortschritt zum nächsten Rang.

Zwölf Auszeichnungen decken den ersten Abschluss, die erste perfekte
Lösung, 24 und 72 Sterne, die sechs Sektorabschlüsse, den gesamten
Kampagnenabschluss und 48 Dreisterne-Lösungen ab. Die Sektorkarte zeigt
erreichte und noch offene Ziele.

`CampaignProgress` berechnet diese Werte aus den bereits gespeicherten,
servergeprüften Ergebnissen. Es gibt keinen zweiten XP-Speicher und keine
zusätzliche Fortschrittstabelle. Ein gescheiterter Speichervorgang kann
keine Sterne, XP, Freischaltungen oder Auszeichnungen vergeben.

## Bedienung und Mehrfachladungen

Maus, Touch und Tastatur bleiben wie bisher. Quellen senden nach einem
festen Plan; die Konsole zeigt ihre Sendetakte. Der Modulinspektor nennt
Zeitfenster, Sendeplan und Reaktorkapazität. Einige neue Reaktoren brauchen
mehrere Impulse. Die Zielanzeige und das Reaktorsymbol zeigen den Ladestand;
`ONLINE` erscheint erst bei vollständiger Ladung. Zusätzliche Impulse
überladen den Reaktor weiterhin.

LIVE schaltet nach dem Start zwischen Nord und Süd. HOLD lässt sich vor
dem Start auf einen bis drei zusätzliche Takte stellen. Verriegelte Module
bleiben fest. Für unbenutzte Sackgassen gibt es keine Anschlusspflicht.
Hinweise begrenzen die Wertung weiterhin auf zwei Sterne.

## Ein Display, AJAX und Vollbild

Sektorkarte, Missionen, Auswertung, Rang und Auszeichnungen befinden sich im
bestehenden `pulsecoredisplay`. Der interne JSON-Output bleibt `pulsecoreapi`.
Es ist kein weiteres Display und kein iframe notwendig.

Der Fragment-Bootstrap funktioniert weiterhin nach abgeschlossenem
Dokumentaufbau. Der AJAX-Host muss den Bootstrap seines vertrauenswürdigen
Fragments ausführen. Versteckte Tabs pausieren und entfernen die aktive
Animationsschleife. Beim Entfernen werden die Ressourcen aufgeräumt;
beim erneuten Laden wird der bestätigte Serverstand wiederhergestellt.

Über **Zum aktuellen Versuch** kann man aus der Sektorkarte zum bisherigen
Schaltkreis zurückkehren, ohne ihn neu zu starten. Ein laufender Versuch
wird dabei pausiert wiederhergestellt. Die API-Operation `resume` setzt nur
die Ansicht zurück auf `game`; Zustand und Versuch-ID bleiben unverändert.

Der Vollbildbutton bleibt oben und verwendet die native Fullscreen API für
das Spielelement. Eine Ablehnung durch Browser oder Host verändert weder
Spielstand noch Einbettung.

## Speicherung und Infrastruktur

Ein Benutzer besitzt weiterhin einen aktuellen Versuch. Mehrere Tabs teilen
sich denselben Spielstand. Die autoritative Zeile in `pulsecore_snapshots`
wird mit einem revisionsgeprüften `UPDATE` geschrieben. Veraltete Tabs
können keinen neueren Stand überschreiben. Bei einem Konflikt oder einer
unklaren Serverantwort pausiert das Spiel und bietet **Serverstand laden** an.

Session und LocalStorage sind keine zusätzlichen Spielstandspeicher.
Die Session enthält nur den accountgebundenen CSRF-Token. Authentifizierung,
Session, Datenbank, LinkTargetService, Logger und EventManager kommen weiterhin
vom Hostprojekt. Die Dienste und Voraussetzungen entsprechen Version 0.2.2.

Das Plugin erfordert PHP 8.1+ sowie einen modernen Browser mit ES-Modulen,
Canvas, ResizeObserver, Container Queries und Dialog-Unterstützung. Es
benötigt keinen Frontend-Build, kein npm-Paket, keine KI-Aufrufe und kein CDN.

## Prüfung und Grenzen

`docs/TESTING.md` beschreibt die reproduzierbaren Tests und
`docs/TEST-RESULTS.md` den tatsächlichen Prüfstand dieser Version.
Die ILIAS-Installation des Projekts wird durch diese isolierten Tests nicht
ersetzt. Datenbankadapter, Migrationen und Snapshot-Schreibpfad bleiben jedoch
unverändert gegenüber der bereits bei dir funktionierenden Version.

Die PHP-Engine bestätigt regelkonforme Aktionen, nicht menschliches Spielen.
Bots können gültige Lösungen einreichen. Reaktionszeiten basieren auf
logischen Takten; die Version beansprucht keine manipulationssichere
Echtzeitmessung oder Turniertauglichkeit.

Dieser Ausbau schließt die vereinbarte **48-Level-Kampagne** ab.
Tagesaufgaben, öffentliche Bestenlisten und auswählbare Kosmetik sind
nicht Bestandteil dieses Patches. Es gibt keine dafür sichtbaren
Platzhalter oder funktionslosen Schaltflächen.

## Finishing 0.3.1

Dieses Update baut auf `PulseCore-0.3.0-step3-unzip-o.zip` mit 48 Missionen auf.
Die Kampagne, alle Leveldefinitionen, Spielregeln, XP, Freischaltungen und
vorhandenen Spielstaende bleiben unveraendert. Datenbankrepository, Services,
Migrationen und Base3Ilias werden nicht geaendert.

Einspielen im Verzeichnis oberhalb von `PulseCore/`:

```sh
unzip -o PulseCore-0.3.1-finishing-unzip-o.zip
```

Danach die Seite bzw. den AJAX-Bereich neu laden. Die Asset-URLs enthalten die
neue Versionsnummer. Es gibt keine neue Migration und keine geaenderte
Display-Konfiguration. Der technische Name bleibt `pulsecoredisplay`.

### Sound

Oben auf **Sound aus** tippen: Drei kurze Toene bestaetigen die Aktivierung.
Unter **? > Ton & Lautstaerke** befinden sich ein Lautstaerkeregler und ein
Testton. Es gibt lokale Spieleffekte, keine Hintergrundmusik oder Audiodownloads.
Audio muss vom Browser tatsaechlich freigegeben sein; eine fehlgeschlagene oder
unterbrochene Aktivierung wird angezeigt. Ein laenger blockierter
Aktivierungsversuch wird nach drei Sekunden mit einer Meldung beendet.

Ausblenden eines AJAX-Tabs stoppt geplante Toene und suspendiert den Audiokontext.
Die naechste ausdrueckliche Bedienung kann einen aktivierten Kontext fortsetzen.
Entfernen des Displays schliesst ihn. Ton und Lautstaerke gelten fuer die
aktuelle Displayinstanz; eine neu erzeugte Instanz beginnt wieder mit Ton aus
und 70 Prozent Effektlautstaerke. Es gibt keine parallele Speicherung in
LocalStorage, Sessions oder einer neuen Datenbankstruktur.

Ein laufender Audiokontext kann keine stummgeschalteten Lautsprecher, eine
falsche Systemausgabe oder Betriebssystem-Einstellungen erkennen oder umgehen.
Bleibt der Testton unhoerbar, die Browser-/Geraetelautstaerke und Audioausgabe
pruefen. Der Schalter zeigt den Browser-Audiozustand, nicht den Lautsprecher.

### Mobile Bedienung

Kurzes Tippen dreht ein Modul rechts, Gedrueckthalten dreht es nach etwa
0,43 Sekunden einmal links. Das Loslassen erzeugt keinen zweiten Zug. Bewegung
oder ein abgebrochener Pointer vor Ablauf stoppt den Halte-Timer; Scrollen und
Pinch-Zoom bleiben Browsergesten. Auf kleinen Feldern helfen die mindestens
48 Pixel grossen Drehknopfe unmittelbar unter dem Spielfeld.

Fuer LIVE-Module stehen dort eigene Direkt-Tasten mit Feldkoordinate und
aktueller Richtung bereit. Die Tasten arbeiten ueber denselben Spielkern und
dieselben servergeprueften Aktionen wie das Modul. Sie sind vor dem Start, bei
Pause, bei einer Speichersperre und nach Abschluss deaktiviert. Maus,
Rechtsklick, Shift-Klick und Tastatur bleiben unterstuetzt.

### Effekte

Drehen, Einspeisung, Fusion, Zielversorgung und Fehler erhalten kurze
Funken-/Ringeffekte. Der Renderer begrenzt sie auf 180 Partikel und 16 Ringe.
Reduzierte Bewegung deaktiviert die dekorativen Effekte und leert laufende
Effekte. In der Kampagnenansicht wird das verdeckte Spielfeld nicht pro Frame
neu gezeichnet.

Pruefstand und Grenzen stehen in `docs/FINISHING-TESTS.md`.

## BASE3 feature authorization (ILIAS 11 integration)

`PulseCorePlayFeature` is discoverable through `IClassMap` as an `IFeature`.
It declares `feature/pulsecoreplayfeature`; its default audience expression matches
the BASE3 group `authenticated`. The ILIAS-hosted Features UI may override that
with any combination of BASE3 groups, individual ILIAS users or discovered
`IUserCriterion` expressions (for example an email-domain criterion). An explicit
empty expression denies access. Both `pulsecoredisplay` and `pulsecoreapi` require
`IAuthorizationService::decide()` to return `ALLOW`; server-side operations are
protected independently of the UI. Feature rules are persisted only by the host in
`base3_authorization_features/catalog` after the one-time migration from legacy
`grants` to `rules`; PulseCore has no separate authorization table.
See `PRIVACY.md` for host-specific data-processing and retention boundaries.
