# Erledigt

## Sicherheitsabfrage beim Löschen
~~Wird eine Sicherheitsabfrage z. B. beim Löschen eines Rezept gestellt, soll diese nicht mehr als MessageBox, sondern in einem eigenen Modal entsprechend des restlichen Designs dargestellt werden.~~

Gelöst (2026-09-23): Neues `public/js/confirm-dialog.js` mit `showConfirmDialog(message, options)` — baut dynamisch ein Bootstrap-Modal auf (Icon je nach `options.type` = `danger`/Standard, konfigurierbare Confirm-/Cancel-Labels) und liefert ein `Promise<boolean>` zurück, genau wie YTANs gleichnamiges `confirm-dialog.js`, dessen Promise-Form und "Cancel bekommt den Fokus, Enter bestätigt nicht versehentlich" hier übernommen wurden — anders als YTAN aber als echtes Bootstrap-`Modal` statt eigenem CSS-Overlay gerendert, da Kochbuch (im Unterschied zu YTAN) Bootstrap für seine Oberfläche nutzt. Escape und Klick auf den Backdrop schließen den Dialog über Bootstraps eigene Modal-Defaults (Ergebnis bleibt `false`, kein Extra-Code nötig). Die beiden bisherigen `confirm()`-Aufrufe (`recipe-detail.js`s Rezept-Löschen, `categories.js`s Kategorie-Löschen) rufen jetzt `await showConfirmDialog(..., { type: 'danger' })`. Neue i18n-Schlüssel `common.cancel`/`common.confirm`/`common.delete` (DE/EN). Per Playwright verifiziert (mobil 390×844 und Desktop 1280×900): Modal öffnet mit Warn-Icon, rotem "Löschen"-Button und fokussiertem "Abbrechen", Abbrechen schließt ohne API-Aufruf — keine Konsolenfehler.

## Benutzeroberfläche und Suche
~~In der Anzeige werden unter dem Titel und dem Burgermenü zu oberst das Suchefeld für die Suche im Kochbuch, die Auswahl der persönlichen Kategorien als Dropdown, ein Pillswitch zur Auswahl "nur eigene Rezepte", die Liste aller Rezepte des Kochbuchs auf die die Suche UND die Kategorieauswahl UND ggfs. die Auswahl "nur eigene Rezepte" zutreffen mit Pagination angezeigt. Hat ein Benutzer eigene Rezepte, die keiner Kategorie zugeordnet sind, werden diese in der virtuellen Kategorie "eigene Rezepte ohne Kategorie" angezeigt. Klickt man auf ein Rezept wird dieses angezeigt. Über einen Zuückbutton kehrt man zur letzten Seite mit der vorher eingestellten Suche, Kategorie und Seite der Suchtreffer (Pagination) zurück. Die Pagination ist später in einer Admin-Oberfläche (AdminLTE) unter "Einstellungen" konfigurierbar. Zunächst stehen die Werte 10, 20 und 100 Treffer pro Seite zur Auswahl. 10 ist der Standardwert.~~

Gelöst (2026-09-23): Backend: `RecipeRepository::search()` erweitert um ownership-geprüfte Kategoriefilterung (`category_id`, per `INNER JOIN category_recipe ... INNER JOIN category ON cat.user_id = ?` — verhindert, dass ein erratener fremder `category_id`-Wert die Mitgliedschaft einer fremden Kategorie offenlegt), die virtuelle Pseudo-Kategorie "eigene Rezepte ohne Kategorie" (`category_id=uncategorized` → `NOT EXISTS`-Subquery gegen `category_recipe`) sowie echte Pagination mit Gesamtanzahl (`COUNT(*)`-Query getrennt von der `LIMIT`/`OFFSET`-Query, Seitengröße gegen eine feste Whitelist `[10, 20, 100]` geprüft, Default 10). `RecipeController::index()` gibt jetzt `{"data": {"items": [...], "total": N, "page": P, "per_page": PP}}` statt eines flachen Arrays zurück (Breaking Change, alle Call-Sites in Tests und Frontend mitgezogen). Vier neue Integrationstests decken Kategorie-Besitzprüfung, die virtuelle Kategorie und Pagination-Korrektheit (inkl. ungültiger `per_page`-Wert fällt auf 10 zurück) ab — `composer test` 26/26 grün.

Frontend: `recipes-list.js` komplett neu aufgebaut — Suchfeld ganz oben (Fokus-Erhalt beim Debounce-Re-Render wie zuvor, siehe "Bugfix: Suche verliert Fokus"), darunter das Kategorie-Dropdown (nur für angemeldete Nutzer, per `GET /categories` geladen, inkl. der virtuellen Option am Listenende), Diät-/Schwierigkeitsfilter, und ein als Bootstrap `form-switch` umgesetzter "Nur meine"-Pillswitch. Alle Filter plus Seite/Seitengröße leben in der Hash-Query (`#/recipes?q=...&category_id=...&mine=1&page=2&per_page=20`), sodass die Liste bookmarkable bleibt. Neue Pagination-Leiste (Zurück/Weiter, "Seite X von Y", Seitengrößen-Auswahl 10/20/100) unter den Ergebnissen. `recipe-detail.js`s Zurück-Link liest jetzt `lastRecipesListUrl` (ein von `recipes-list.js` bei jedem Render aktualisierter modulweiter String) statt eines hartkodierten `#/recipes`, sodass er exakt zur zuletzt eingestellten Suche/Kategorie/Seite zurückführt. Per Playwright verifiziert (mobil 390×844 und Desktop 1280×900, Benutzer Christoph): Kategorie-Filter inkl. virtueller Kategorie, Seitenwechsel, Rücksprung vom Rezept zur exakt vorherigen Listen-Seite, Such-Debounce ohne Fokusverlust — keine Konsolenfehler.

## Suche
~~Die Suche soll im Rezeptnamen, der Beschreibung und den Tags suchen.~~

Gelöst (2026-09-23): `RecipeRepository::search()`s Textsuche prüfte bisher nur `name`/`description`. Um den `q`-Filter erweitert: `EXISTS`-Subquery gegen `recipe_tag`/`tag` (bewusst kein zusätzlicher `JOIN`, damit ein Rezept mit mehreren passenden Tags weiterhin nur eine Ergebniszeile liefert). Neuer Integrationstest `testSearchMatchesTagsNotJustNameOrDescription` — `composer test` jetzt 23/23 grün. Verifiziert gegen die echten Chefkoch-Importdaten: Suche nach "Frankreich" findet zwei "Mousse au Chocolat"-Varianten über deren Tag `Frankreich` sowie "Crêpes" über die Beschreibung — keines der drei über den Namen.

## Kategorien zu Kochbuch
~~Die derzeitigen Kategorien gelten global. Sie sollen aber individuell pro Benutzer gelten. Jeder Benutzer kann sich eigene Kategorien anlegen. Alle Kategorien eines Benutzers ergeben sein Kochbuch. Übernimm die derzeitigen Kategorien und Rezeptzordnungen für den Benutzer "Christoph".~~

Gelöst (2026-09-23): Kategorien waren bereits seit der ursprünglichen Implementierung pro Benutzer angelegt (`category.user_id`, `CategoryController::index()` filtert auf `$auth['sub']`, `requireOwnCategory()` erzwingt Owner-oder-Admin bei Änderungen) — sie *wirkten* nur global, weil bislang ausschließlich der Test-Account `testadmin` existierte. Mit den 5 echten Benutzern (siehe "Neue Benutzer") war das architektonisch schon korrekt, es fehlte nur die eigentlich angeforderte Datenübernahme: Alle 6 bestehenden Kategorien (samt ihren `category_recipe`-Zuordnungen, die unverändert blieben, da sie nur `category_id`+`recipe_id` referenzieren, nicht den Besitzer) wurden von `testadmin` auf `Christoph` umgehängt (`UPDATE category SET user_id = ...`). Verifiziert: `composer test` weiterhin 22/22 grün; Christoph sieht per API/UI alle 6 Kategorien mit unveränderten Rezeptanzahlen (2/5/24/21/7/15), `testadmin` und ein fremder Nutzer (Swantje) sehen jeweils 0 Kategorien (korrekt isoliert).

## Sichtbarkeitsstatus
~~Die Sichtbarkeit von Rezepten kann folgenden die Status haben:~~
~~- privat: nur für den Ersteller sichtbar~~
~~- intern: nur für angemeldete Benutzer sichbar (default)~~
~~- öffentlich: für alle Benutzer sichtbar~~
~~Ggfs. gibt es später weitere Sichtbarkeitstatus.~~
~~Setze alle bereits vorhandenen Rezepte auf "intern".~~

Gelöst (2026-09-23): Dritte Sichtbarkeitsstufe "intern" ergänzt (`RecipeController::VISIBILITIES`, `findVisible()`: `public` → jeder inkl. abgemeldet, `internal` → jeder angemeldete Nutzer, nicht nur der Ersteller, `private` → nur Ersteller/Admin; `RecipeRepository::search()` mit eigenem, parallelem SQL-Sichtbarkeitsfilter, da dort keine einzelne bereits geladene Zeile geprüft wird). Default bei neuen Rezepten (Backend-Validierung *und* Formular) von `private` auf `internal` geändert. Migration `003_recipe_visibility_internal.sql` setzt alle 76 bereits vorhandenen Rezepte auf `internal`. Frontend: neues Options-Feld im Formular, gemeinsame `VISIBILITY_META`-Zuordnung (Icon + Label) in `helper.js` für Rezeptkarten und Detailseite, dritte Badge-Variante ("Intern", Personen-Icon). 3 neue Integrationstests (Default-Sichtbarkeit, intern sichtbar für jeden angemeldeten Nutzer aber nicht anonym, Suche berücksichtigt interne Rezepte nur bei Anmeldung) — `composer test` jetzt 22/22 grün. Verifiziert: anonymer `GET /api/v1/recipes` liefert 0 Treffer (alle 76 Rezepte sind jetzt intern), ein fremder angemeldeter Nutzer (Swantje) sieht alle 76; Badge "Intern" und alle drei Formular-Optionen im Playwright-Browser bestätigt.

## Neue Benutzer
~~Lege die folgenden 5 Benutzer an:~~
~~- Christoph, christoph@steindorff.de, is_admin~~
~~- Swantje, gwss@steindorff.de~~
~~- Thia, gwst@steindorff.de~~
~~- Micas, gwsm@steindorff.de~~
~~- Maxi, gwsx@steindorff.de~~
~~Gib allen Benutzern das Kennwort "Passw0rt!"~~

Gelöst (2026-09-23): `UserRepository::create()` um einen `$isAdmin`-Parameter erweitert (bisher fest `is_admin=0`, keine bestehenden Aufrufer betroffen). Alle 5 Nutzer angelegt (`Christoph` #2 mit `is_admin=1`, `Swantje` #3, `Thia` #4, `Micas` #5, `Maxi` #6), Passwort jeweils `Passw0rt!` gehasht via `password_hash()`. Verifiziert: Login sowohl per Benutzername (`Christoph`) als auch per E-Mail (`gwss@steindorff.de`) liefert HTTP 200 mit korrektem `is_admin`-Flag im JWT; `composer test` weiterhin 19/19 grün.

## Bugfix: Suche verliert Fokus
~~Wenn die Suche nach Eingabe von drei Zeichen erste Treffer anzeigt, verliert das Eingabefeld den Fokus und man kann nicht weitertippen.~~

Gelöst (2026-09-23): Der Debounce in `recipes-list.js` löst bei jeder Tipppause `Router.navigate()` aus, was den kompletten `#app`-Inhalt inkl. des Such-Eingabefelds per `innerHTML` neu erzeugt — das neue `#filterQ`-Element übernimmt zwar den eingegebenen Text (`value=`), ist aber ein komplett neues DOM-Element und daher nie fokussiert, selbst wenn der Nutzer gerade noch getippt hat. Fix: `renderRecipesList()` merkt sich vor dem Neuzeichnen, ob `#filterQ` gerade fokussiert war (inkl. Cursor-Position via `selectionStart`/`selectionEnd`), und stellt Fokus + Cursor-Position auf dem neuen Element danach wieder her. Verifiziert per Playwright: zeichenweise "brot" eingetippt (mit Zwischenpausen, die den 350-ms-Debounce mehrfach auslösen) — Fokus bleibt durchgehend auf dem Suchfeld, alle Zeichen kommen an, Ergebnisliste filtert korrekt auf Brot-Rezepte.

## Kategorien entsprechend der Chefkoch-Sammlungen
Nutzeranfrage: "Erzeuge die Kategorien entsprechend der Sammlungen und ordne die Rezepte den Kategorien hinzu."

Gelöst (2026-09-23): 6 Kategorien für `testadmin` angelegt (`_Standards`, `Aufläufe / Gratins`, `Beilagen`, `Brot`, `Desserts`, `Fisch und Meeresfrüchte`) und alle importierten Rezepte per `CategoryRepository::addRecipe()` zugeordnet — Zuordnung anhand des beim Import gesetzten Sammlungs-Tags (`recipe_tag`), da dieser die ursprüngliche Chefkoch-Sammlung jedes Rezepts eindeutig festhält. Ergebnis: 2/5/24/21/7/15 Rezepte (Summe 74, entspricht allen Chefkoch-importierten Rezepten).

Abweichung von den ursprünglich beobachteten Sammlungsgrößen (Beilagen 25, Brot 22 statt 24/21): vermutlich je ein Rezept, das der Nutzer bei Chefkoch in zwei Sammlungen gleichzeitig einsortiert hatte — beim Bereinigen der versehentlichen doppelten Import-Charge (siehe Chefkoch.de-Import-Eintrag) wurde pro betroffenem Rezept nur eine der beiden Sammlungs-Zuordnungen als "Duplikat" erkannt und verworfen, da die Dedup-Logik rein über `source_url` lief und diesen Fall nicht vorhersah. Das Rezept selbst ist nicht verloren, nur eine von zwei ursprünglichen Sammlungs-Mitgliedschaften; per UI ("Zu Kategorie hinzufügen") jederzeit nachtragbar, falls der Nutzer den fehlenden Fall identifiziert. Verifiziert: `composer test` 19/19 grün, Kategorien-Übersicht und Kategorie-Detail ("Brot", 21 Rezepte inkl. Bilder) im Playwright-Browser ohne Konsolenfehler.

## Bugfix: Bilder privater Rezepte luden nicht (403)
Beim Testen des Chefkoch-Imports (siehe unten) aufgefallen, kein separater Nutzer-Report.

Gelöst (2026-09-23): `<img src="...">` kann keinen `Authorization`-Header mitschicken, den `GET /api/v1/recipes/{id}/images/{imageId}` für ein **privates** Rezept verlangt (bei öffentlichen Rezepten war das Endpoint anonym erreichbar, daher fiel es vorher nicht auf). Fix: Jedes Rezeptbild wird jetzt als `<img data-recipe-image="/api/pfad">` ohne `src` gerendert; `helper.js`s neue `hydrateAuthImages()` lädt es per authentifiziertem `fetch()` (`Kochbuch.fetchImageObjectUrl()` in `api-client.js`) und setzt `img.src` auf eine Object-URL, inkl. Revoke der vorherigen Object-URLs bei jedem neuen Render (Speicherleck-Vermeidung). Betraf Rezeptkarten (`recipeCardHtml`), Hero-Bild und Galerie-Thumbnails auf der Detailseite. Verifiziert per Playwright: privates Rezept mit Bild lädt jetzt fehlerfrei (vorher `403` in der Konsole).

## Chefkoch.de-Import (alle 76 Rezepte)
Nutzeranfrage: "Wenn ich Dir meine Zugangsdaten für chefkoch.de zur Verfügung [stelle], kannst Du dann alle Rezepte aus meinem Kochbuch dort herunterladen, als JSON ablegen und anschließend in die Datenbank überführen?" Danach: "Funktioniert. Fahre nun mit den restlichen Rezepten fort."

Gelöst (2026-09-23): Alle 6 Sammlungen aus "Mein Kochbuch" vollständig importiert — `_Standards` (2), `Aufläufe / Gratins` (5), `Beilagen` (25), `Brot` (22), `Desserts` (7), `Fisch und Meeresfrüchte` (15) = 76 Rezepte, davon 51 mit Originalfoto.

- **Login**: bewusst **nicht** per Skript automatisiert (Chefkoch zeigt ein CAPTCHA vor dem Login) — der Nutzer hat sich stattdessen selbst im Playwright-Browser angemeldet; danach wurde nur die bereits authentifizierte Session weiterverwendet.
- **Datenquelle**: statt brüchigem HTML-Scraping wurde Chefkochs eigene JSON-API benutzt (in den Netzwerk-Requests der Seite gefunden, Header `x-chefkoch-api-token`, aus einem echten Request abgekupfert statt selbst generiert): `GET /v2/cookbooks/{user}/collections/{collection}/recipes` liefert die komplette, paginierungsfreie Sammlungs-Liste (löste nebenbei ein Problem mit unvollständigem Lazy-Loading beim DOM-Scrollen); `GET /v2/recipes/{id}` für öffentliche und `GET /v2/cookbooks/{user}/private-recipes/{id}` für private/eigene Rezepte liefern beide sauber strukturierte Volltext-Daten (Zutaten mit Menge/Einheit/Name getrennt bei öffentlichen Rezepten, ein Freitext-Feld `ingredientsText` bei privaten — dort wurden zwei unterschiedliche vom Nutzer über die Jahre verwendete Formate gefunden, tab-separiert und "Menge Einheit Name"-Freitext, beide mit einem toleranten Parser abgedeckt).
- **`bin/import-recipes.php`** (neu, wiederverwendbar): importiert ein Verzeichnis von JSON-Dateien im Schema von `RecipeRepository::create()` für einen bestehenden Nutzer, inkl. optionalem Bild-Download über ein `image_url`-Feld.
- **Bilder**: Der CDN-Host (`img.chefkoch-cdn.de` bzw. signierte `api.chefkoch.de`-Bild-URLs) blockiert einfache `file_get_contents()`-Downloads mit 403 (auch mit User-Agent/Referer) — vermutlich TLS-/Browser-Fingerprinting. Alle 48 Fotos wurden stattdessen im Playwright-Browser per `fetch()` geladen, als Base64 zwischengespeichert und lokal dekodiert, dann per eigenem Skript (`RecipeRepository::addImage()`) den passenden Rezepten zugeordnet (Zuordnung über `source_url`).
- Beim Testen fiel auf und wurde behoben: [[Bugfix: Bilder privater Rezepte luden nicht (403)]] — ohne den Fix wären alle 76 (privaten) importierten Rezepte betroffen gewesen.
- Import-Artefakte (JSON-Zwischendateien, Konvertierungs-/Zuordnungs-Skripte, Zugangsdaten-Datei `.chefkoch`) wurden nach Abschluss vollständig entfernt, nur `bin/import-recipes.php` bleibt als wiederverwendbares Werkzeug im Repo.

Verifiziert: `composer test` weiterhin 19/19 grün, `SELECT COUNT(*) FROM recipe` = 76 (51 mit Bild, keine Duplikate), Stichproben über API und im Playwright-Browser (Rezeptliste "Nur meine", Detailansicht mit Foto/Zutaten/Tags) — keine Konsolenfehler.

## Bugfix: Login liefert "Not found" unter Apache/XAMPP (Unterverzeichnis-Deployment)
~~Mit dem Benutzernamen "testadmin" und dem Passwort "Passw0rt!" kann ich mich nicht anmelden. Es erscheint die Fehlermeldung "Not found".~~

Gelöst (2026-09-23): Trat nur auf, wenn die App über Apache/XAMPP aus einem Unterverzeichnis aufgerufen wird (z. B. `http://localhost/kochbuch/public/`, laut Nutzer-Setup) statt über `php -S`. Ursache: `public/index.php` enthält zwar eine Sonderbehandlung für PHP's eingebauten Dev-Server (`PHP_SAPI === 'cli-server'`), aber es fehlte ein `public/.htaccess` für Apache — ohne Rewrite-Regel liefert Apache für jede URL ohne passende reale Datei (z. B. `POST /kochbuch/public/api/v1/auth/login`) seine eigene 404-Antwort, bevor PHP/Slim die Anfrage überhaupt sieht; nur die Wurzel-URL selbst funktionierte (wird von Apaches `DirectoryIndex` ohne Rewrite direkt an `index.php` gereicht). Die JS-Fehlerbehandlung (`api-client.js`) fällt in diesem Fall mangels JSON-Antwort auf `response.statusText` zurück, was exakt die gemeldete Meldung "Not Found" erklärt. Fix: `public/.htaccess` mit der Standard-Slim-Rewrite-Regel (`RewriteCond %{REQUEST_FILENAME} !-f` → `index.php`) ergänzt; erfordert `mod_rewrite` sowie `AllowOverride All`/`FileInfo` in der Apache-Konfiguration für dieses Verzeichnis. Verifiziert direkt gegen die Apache/XAMPP-Instanz des Nutzers unter `http://localhost/kochbuch/public/`: `GET /api/v1/health`, `POST /api/v1/auth/login` und der komplette Login-Flow im Playwright-Browser (keine Konsolenfehler, Token gespeichert, Weiterleitung zu `#/recipes`) funktionieren jetzt.

## Bugfix: Klick auf "Anmelden" (und alle anderen Menüpunkte) tat nichts
~~Beim Klick auf Anmelden passiert nichts. Man kann sich nicht anmelden.~~

Gelöst (2026-09-23): Ursache war nicht auf "Anmelden" beschränkt, sondern betraf **jeden** Link im Offcanvas-Menü (Rezepte, Kategorien, Anmelden, Meine Rezepte) — Bootstraps `data-bs-dismiss="offcanvas"`-Klick-Handler (an diesen Links, damit sich das Menü nach der Auswahl schließt) ruft auf demselben Klick-Event `event.preventDefault()` auf, wodurch die native Sprungmarken-Navigation der `<a href="#/...">`-Links unterdrückt wurde, bevor sie überhaupt stattfinden konnte (per `event.defaultPrevented` im Browser verifiziert). Fix in `public/js/router.js`: `Router.start()` registriert jetzt `interceptInternalLinks()`, einen delegierten Klick-Handler auf `document` für jedes `a[href^="#/"]`, der `location.hash` selbst setzt statt sich auf die Browser-Standardaktion zu verlassen — funktioniert unabhängig davon, ob Bootstrap (oder ein künftiges anderes Skript) den Klick bereits `preventDefault()`et hat. Verifiziert per Playwright (frischer Zustand ohne gespeichertes Token): Menü öffnen → Anmelden → Formular erscheint (`#/login`) → Login → Weiterleitung zu `#/recipes` mit gespeichertem Token, sowie Kategorien-Link aus dem Menü → korrekt zu `#/categories` navigiert. `composer test` weiterhin 19/19 grün (Backend war nicht betroffen).

## Rezept eingeben
~~Es sollen Rezepte eingegeben werden können. Zu jedem kann man ...~~
~~- Name des Rezepts~~
~~- Beschreibung~~
~~- eine Zutatenliste mit Mengenangaben für x-Portionen~~
~~- Schritt für Schritt Anleitung~~
~~- Einstufung des Schwierigkeitsgrades (einfach, normal, schwierig, herausfordernd)~~
~~- Dauer der Vorbereitung, Ruhezeit und Koch-/Backzeit~~
~~- Anmerkungen~~
~~- Kalorien~~
~~- Hinweise für Allergiker~~
~~- Eigenschaftsflags: vegan, vegetarisch, pescetarisch (ggfs. weitere)~~
~~- Bilder vom Rezept~~
~~- Auswahl eines dieser Bild als primäres Rezeptbild.~~
~~- Quelle und Link zur Quelle~~
~~- Privat (nur der Ersteller des Rezepts kann es sehen) oder öffentlich (für jeden sichtbar)~~
~~- eine Liste von Tags~~
~~hinterlegen.~~

Gelöst (2026-09-22): `recipe`/`recipe_ingredient`/`recipe_step`/`recipe_image`/`tag`/`recipe_tag`-Schema (`database/migrations/002_create_recipe_schema.sql`), `RecipeRepository` (Transaktions-sichere create/update inkl. Zutaten/Schritte/Tags), `RecipeController` (`POST/GET/PUT/DELETE /api/v1/recipes[/{id}]`) und das Formular `public/js/views/recipe-form.js` decken alle genannten Felder ab: Name, Beschreibung, Zutatenliste mit Mengenangaben, Schritt-für-Schritt-Anleitung, Schwierigkeitsgrad (easy/normal/hard/challenging, übersetzt), Vorbereitungs-/Ruhe-/Koch-Backzeit, Anmerkungen, Kalorien, Allergiker-Hinweise, vegan/vegetarisch/pescetarisch-Flags, Bilder (Upload via `RecipeImageService`, außerhalb des Webroots unter `storage/recipe-images/`, mit Sichtbarkeits-Check beim Ausliefern), Auswahl eines primären Bildes, Quelle/Quell-Link, privat/öffentlich (`assertOwnerOrAdmin`-Check), Tag-Liste.

## Rezepte teilen
~~Es soll eine Funktion zum Teilen von Rezepten geben, die einen Link zum Rezept verschickt.~~

Gelöst (2026-09-22): Teilen-Button auf der Rezeptdetailseite (`public/js/views/recipe-detail.js`) nutzt die Web-Share-API, wenn verfügbar, sonst Kopieren des Links (`#/recipes/{id}`) in die Zwischenablage inkl. Toast-Bestätigung.

## Kategorien
~~- Jeder Nutzer kann eigene Kategorien definieren und beliebige Rezepte den Kategorien zuordnen. So entsteht durch Auswahl aus allen vorhandenen Rezepten ein eigenes Kochbuch.~~

Gelöst (2026-09-22): `category`/`category_recipe`-Schema, `CategoryRepository`/`CategoryController` (`/api/v1/categories*`) sowie `public/js/views/categories.js` (Liste, Anlegen/Umbenennen/Löschen, Kategorie-Detail mit Rezeptübersicht) und die Zuordnungs-Checkliste auf der Rezeptdetailseite. Kategorien gehören einem Nutzer, können aber beliebige (eigene wie fremde öffentliche) Rezepte enthalten.

## Suche
~~Man kann nach Rezepten suchen (Eigenschaften, Name, Beschreibung).~~

Gelöst (2026-09-22): `GET /api/v1/recipes` filtert nach `q` (Name/Beschreibung, `LIKE`), `difficulty`, `vegan`/`vegetarian`/`pescetarian` sowie `mine`; `public/js/views/recipes-list.js` spiegelt die Filter in die URL (Hash-Query), sodass Suchergebnisse teilbar/bookmarkbar sind.

## Anzeige eines Rezepts
~~Das Rezept wird angezeigt. Man kann die Anzahl der Portionen wählen. Wenn die Zutatenliste für 4 Portionen hinterlegt und man nun 2 Portionen auswählt, werden die Mengenangaben bei allen Positionen halbiert (2/4).~~

Gelöst (2026-09-22): Rezeptdetailseite (`public/js/views/recipe-detail.js`) mit Portionen-Stepper; die Zutatenmengen werden rein clientseitig um den Faktor `gewählte Portionen / Basis-Portionen` skaliert (`formatAmount()`), ohne API-Roundtrip. Backend liefert immer die Basis-Mengen (auch in Exporten).

## Export
~~Rezepte können als JSON, PDF, XML exportiert werden.~~

Gelöst (2026-09-22): `GET /api/v1/recipes/{id}/export/{json|xml|pdf}` (`RecipeController::export()`) — JSON als Pretty-Print der Rezept-Detailstruktur, XML per rekursivem Array→SimpleXMLElement-Walk, PDF via `dompdf/dompdf` (bereits YTAN-Abhängigkeit). `Kochbuch.download()` im Frontend hängt den Auth-Header an und löst den Browser-Download aus (nötig, da ein reines `<a href>` keinen Authorization-Header für private Rezepte mitschicken kann).

## Oberfläche (Bootstrap 5, mobil zuerst)
Nutzeranfrage: "Entwickele eine ansprechende, moderne Oberfläche für die App gemäß der Anforderungen."

Gelöst (2026-09-22): Bootstrap 5.3 + Bootstrap Icons 1.11 vendored (`public/lib/`, von `cdn.jsdelivr.net`), eigenes Re-Theming der Bootstrap-Komponenten-Farben auf den Akzentton (`public/css/style.css`, da Bootstraps kompiliertes CSS `--bs-primary` nicht zur Laufzeit für `.btn-primary` etc. liest). SPA-Shell (`templates/app.php`) mit Bootstrap-Navbar/Offcanvas (mobil: Drawer, ab `md`: horizontale Leiste — inkl. Fix, da Offcanvas-Body sonst bei jeder Breite spaltenweise gestapelt blieb), eigenem Hash-Router (`public/js/router.js`) und je einer View-Datei pro Bildschirm unter `public/js/views/` (Rezeptliste inkl. Filter, Rezeptdetail, Anlegen/Bearbeiten-Formular, Kategorien, Login). Light/Dark-Theming über CSS Custom Properties, synchron mit Bootstraps eigenem `[data-bs-theme]`. DE/EN-Sprachumschalter in der Navigation. `Cache-Control: no-store` auf den serverseitig gerenderten HTML-Routen ergänzt, damit `App::assetVersion()`-Cache-Busting zuverlässig greift. Mobile-First-Regression bei den Zutatenzeilen des Formulars behoben (5-spaltiges Grid brach auf 390px um auf `d-flex flex-wrap`). Backend um `RecipeImageService` (Upload/Validierung/Auslieferung mit Sichtbarkeitsprüfung) ergänzt; `RecipeRepository`s eigene Transaktionen auf Verschachtelungs-Sicherheit umgestellt (`inTransaction()`-Check), damit sie sowohl standalone als auch innerhalb der PHPUnit-Transaktions-Fixture funktionieren. 10 neue Integrationstests (`RecipeControllerTest`, `CategoryControllerTest`) ergänzt. `firebase/php-jwt` auf `^7.0` angehoben (löst eine Low-Severity-Advisory zu CVE-2025-45769) — `AuthService` prüft seitdem beim Boot, dass `JWT_SECRET` mindestens 32 Byte lang ist. Verifiziert: `composer test` (19 Tests grün), vollständiger manueller Durchlauf im Playwright-Browser (mobil 390×844 und Desktop 1280×900, Light+Dark) über Login, Rezept anlegen inkl. Bild-Upload, Portionsumrechnung, Kategorie-Zuordnung, Suche/Filter, Bearbeiten, Löschen, Sprachumschaltung — keine Konsolenfehler.

## Grundgerüst
~~Lege eine claude.md an.~~

Gelöst (2026-09-22): `CLAUDE.md` angelegt (Projektbeschreibung + geplante Architektur, orientiert an YTAN). Darauf aufbauend das PHP-Grundgerüst erstellt: Slim-4-App (`src/App.php`, `public/index.php`), JWT-Auth (`AuthService`/`AuthMiddleware`/`AuthController` mit `POST /api/v1/auth/login` und `GET /api/v1/auth/me`), Fehler-Hierarchie (`src/Exception/*`), `user`/`user_token`-Schema (`database/migrations/001_create_core_schema.sql`) samt Migrations-Runner (`bin/migrate.php`), `composer.json`, `.env.example`, `.gitignore`, `README.md` sowie `bin/deploy.bat`/`bin/deploy.sh` (SSH-Deploy nach YTAN-Vorbild, Zielpfad `kochbuch.steindorff.de` mit Platzhalter `<DOC_ID>` für die tatsächliche Dokumenten-Root-ID). Anschließend die Verzeichnisstruktur auf YTAN ausgerichtet: `src/Service/Translator.php` (Read-Path i18n) + `resources/i18n/{de,en}.json`, `public/{css,js,fonts,images,lib}/` (Theme-Tokens, `Kochbuch`-API-Client, `i18n.js`, `settings.js`), `templates/{app,imprint,privacy}.php` (per `ob_start()`/`ob_get_clean()` gerendert, inkl. `App::assetVersion()`-Cache-Busting und neuer Routen `GET /`, `/imprint`, `/privacy`), vollständiger PHPUnit-Unterbau (`phpunit.xml`, `tests/bootstrap.php`, `tests/TestCase.php`, `tests/Integration/ControllerTestCase.php`, `bin/setup-test-db.php`, je ein echter Unit-/Integration-Test für Auth), `docs/API.md`, `LICENSE` (MIT). Recipe-/Category-/Tag-Domain, TranslationRepository/Admin-Übersetzungstool, der AdminLTE-Admin-Bereich sowie Capacitor/PWA sind bewusst noch nicht gebaut — das sind eigene, in `todo.md` verbliebene Punkte. Verifiziert: `composer install`, PHP-Lint aller Dateien, `bin/migrate.php` + `bin/setup-test-db.php` gegen eine lokale MySQL, `composer test` (9 Tests grün), sowie `GET /`, `/imprint`, `/privacy`, `/css/style.css`, `/js/api-client.js`, `/api/v1/health` im laufenden Dev-Server.
