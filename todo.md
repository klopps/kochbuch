# Offene Punkte

## Android-App: Teilen aus Chrome auf dem Handy prüfen, Release-Signierung
Die Capacitor-App (siehe done.md "Teilen an Kochbuch") ist gebaut, aber noch nicht auf einem Gerät getestet: APK installieren, ein Bild aus Chrome an Kochbuch teilen und prüfen, dass es auf der Import-Seite ankommt. Danach einen eigenen Release-Signierschlüssel anlegen (`bin\build-app.bat release`, `android\keystore.properties`) - der Wechsel von der Debug- zur Release-Signatur erfordert einmal Deinstallieren/Neuinstallieren.

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

Umgesetzt: Mobile First, unendliche Session (JWT), Light/Dark Theming, DE/EN-Sprachumschalter, klare Bootstrap-5-Oberfläche, Admin-Oberfläche mit Benutzerverwaltung/Einstellungen/Übersetzungs-Tool, PWA/Offline-Fähigkeit, Capacitor-App für Android (Debug-Build, siehe done.md). Noch offen: iOS-App (braucht einen Mac), Release-Signierung der Android-App.
