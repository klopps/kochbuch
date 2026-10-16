-- Wer ein Bild hochgeladen hat, darf es wieder löschen (neben Eigentümer/
-- Admin des Rezepts). NULL = unbekannt; bestehende Bilder gehören dem
-- Rezept-Eigentümer, da bis zur Freigabe des Uploads für alle angemeldeten
-- Benutzer nur er hochladen konnte. Wird ein Benutzer gelöscht, bleiben
-- seine Bilder, nur der Uploader wird NULL.
ALTER TABLE recipe_image
    ADD COLUMN user_id INT UNSIGNED NULL DEFAULT NULL AFTER recipe_id,
    ADD CONSTRAINT fk_recipe_image_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE SET NULL;

UPDATE recipe_image ri
JOIN recipe r ON r.id = ri.recipe_id
SET ri.user_id = r.user_id
WHERE ri.user_id IS NULL;
