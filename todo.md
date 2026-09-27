# Offene Punkte

## Last recipies configurable
The number of the shown newest recipies must be configurable in /admin/settings.

## FAVICON with white background
Regenerate favicon.png, icon-192.png, and icon-512.png based on .assets/logo_bg-white.svg.

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

Umgesetzt: Mobile First, unendliche Session (JWT), Light/Dark Theming, DE/EN-Sprachumschalter, klare Bootstrap-5-Oberfläche, Admin-Oberfläche mit Benutzerverwaltung/Einstellungen/Übersetzungs-Tool (siehe done.md). Noch offen: Capacitor-Apps, PWA/Offline-Fähigkeit, Legal-Infos (Impressum/Datenschutz sind als Platzhalterseiten angelegt, Inhalte fehlen noch).
