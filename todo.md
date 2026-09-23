# Offene Punkte

## Exclude-Liste in deploy.bat
Schau Dir die exclude-Liste in der deploy.bat und ergänze diese um Verzeichnisse und Dateien, die zum Betrieb nicht auf dem Produktions-Webserver benötigt werden. 

## Passworteingabefelder mit Klartextdarstellung
Die Eingabefelder für Passwörte müssen die Option erhalten, die Passwörter im Klartext anzuzeigen (siehe Projekt YTAN).


## Allgemein
Kochbuch ist eine Webapp und App die ein Kochbuch abbildet. Das System soll auf Basis von PHP, MySQL, Bootstrap und JavaScript entstehen. Es hat eine Rollen- und Rechtelogik und ist mehrsprachig ausgelegt. Zunächst werden die Sprachen deutsch und englisch benötigt.

- Das System wird nach dem Mobile First Ansatz entwickelt.
- Wenn man angemeldet ist, soll die Session theoretisch unendlich bestehen bleiben.
- Über Capacitor muss eine Android-App und eine iOS-App gebaut werden können.
- Das System muss als PWA auf dem Smartphone nutzbar sein.
- App und PWA müssen offline-fähig sein.
- Es werden die üblichen Legal-Infos benötigt (Datenschutz, Impressum etc.).
- Es sollen ähnliche technische Randbedingungen wie im System YTAN gelten (c:\dev\www\ytan\claude.md).
- Die Gestaltung soll klar und einfach sein.
- Es soll ein Theming geben. Zunächst werden ein Light und ein Dark Mode benötigt.

Umgesetzt: Mobile First, unendliche Session (JWT), Light/Dark Theming, DE/EN-Sprachumschalter, klare Bootstrap-5-Oberfläche. Noch offen: Capacitor-Apps, PWA/Offline-Fähigkeit, Legal-Infos (Impressum/Datenschutz sind als Platzhalterseiten angelegt, Inhalte fehlen noch).

## Eine Verwaltungsoberfläche auf Basis von AdminLTE
Es muss eine Benutzerverwaltung und ein Tool für die Anpassungen der Übersetzungen geben.
Siehe: https://github.com/klopps/ytan

## Benutzerverwaltung
Die Benutzerverwaltung soll der des Projekts YTAN entsprechen (https://github.com/klopps/ytan). JWT-Login, `/api/v1/auth/me`, `user`/`user_token`-Schema stehen bereits — noch offen: Selbstregistrierung gibt es bewusst nicht (wie bei YTAN), aber es fehlt noch der komplette Invite-/Passwort-Reset-Flow, die Admin-Nutzerverwaltung (`/admin/users`) und Rechte-Flags über `is_admin` hinaus.

## Übersetzungen
Die Benutzeroberfläche muss in beliebige Sprachen übersetzt werden können. Dazu gibt es eine Funktion im Admin-Bereich. Benutzer können ihre bevorzugte Sprache wählen.
Siehe: https://github.com/klopps/ytan

Der Lese-Pfad (`Translator`, `resources/i18n/{de,en}.json`) sowie ein Sprachumschalter für Benutzer in der Navigation sind fertig. Noch offen: das Admin-Tool zum Bearbeiten der Übersetzungen selbst (`TranslationRepository` + `/admin/translate`, siehe YTAN).
