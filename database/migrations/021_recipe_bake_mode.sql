-- Backart (bake_mode) und Temperatur (bake_temperature, Grad Celsius
-- 0-800) - NULL bake_mode = "-nein-" (nicht gebacken); die Temperatur wird
-- dann ebenfalls NULL gespeichert (RecipeDataValidator).
ALTER TABLE recipe
    ADD COLUMN bake_mode VARCHAR(20) NULL DEFAULT NULL AFTER calories,
    ADD COLUMN bake_temperature SMALLINT UNSIGNED NULL DEFAULT NULL AFTER bake_mode;
