# Offene Punkte

## Watchlist
Every registered user can maintain a watchlist of recipes they’d like to cook next. At the top of each recipe, there’s a button with an icon; clicking it adds the current recipe to the bottom of this list. The first menu item is the “Watchlist” button. Clicking it opens the list of saved recipes.

Now you can
- rearrange the entries on the list using the handle (on the left),
- delete an entry by clicking the “x” (on the right) (a confirmation prompt will appear), and
- open a recipe by clicking its name.


## Merkliste
Jeder angemeldete Benutzer kann eine Merkliste mit Rezepten pflegen, die er als nächstes kochen möchte. Bei jedem Rezept wird im oberen Bereich ein Button mit Symbol angezeigt, bei dessen Klick das aktuelle Rezept ans Ende dieser Liste gesetzt wird. Im Menü hat man als ersten Menüpunkt den Button "Merkliste". Klickt man darauf, öffnet sich die Liste der gemerkten Rezepte. Nun kann man
- mittels eines Anfassers (links) die Einträge auf der Liste neu anordnnen,
- durch Klick auf ein x (rechts) löschen und
- durch Klick auf den Rezeptnamen das Rezept öffnen.

## Android-App: Release-Signierung
Die App läuft bisher mit der Debug-Signatur (Download: https://kochen.steindorff.de/app/kochbuch.apk, hochgeladen mit `bin\publish-app.bat`). Für eine dauerhafte Version einen eigenen Release-Signierschlüssel anlegen (`bin\build-app.bat release`, `android\keystore.properties`, Schlüssel sichern) - der Wechsel von der Debug- zur Release-Signatur erfordert einmal Deinstallieren/Neuinstallieren.

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
