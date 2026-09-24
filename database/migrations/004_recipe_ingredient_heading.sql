-- todo.md "Verbesserung Zutatenliste": ingredient lists can now be broken up
-- with section headings (e.g. "Sauce") interspersed between real ingredient
-- rows. A heading row reuses the existing `name` column for its text and
-- leaves amount/unit/note NULL (already nullable) - `is_heading` is the only
-- new column needed to tell the two kinds of row apart, both in the DB and
-- in the JSON/XML/PDF export shape.
ALTER TABLE recipe_ingredient
    ADD COLUMN is_heading TINYINT(1) NOT NULL DEFAULT 0 AFTER position;
