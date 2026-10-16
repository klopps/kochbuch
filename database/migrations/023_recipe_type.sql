-- Rezepttyp: steuert die Darstellung eines Rezepts. 'general' =
-- Allgemeines Rezept (bisheriges Verhalten, Standard für alle bestehenden
-- Rezepte), 'baking' = Backrezept (zeigt statt der Gesamtzeit die
-- Back-/Kochzeit). Weitere Typen: neuer Wert in
-- RecipeDataValidator::RECIPE_TYPES und helper.js's RECIPE_TYPES.
ALTER TABLE recipe
    ADD COLUMN recipe_type VARCHAR(20) NOT NULL DEFAULT 'general' AFTER user_id;
