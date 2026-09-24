-- todo.md "Eingabe der Zubereitungsschritte": steps get the same
-- section-heading treatment as recipe_ingredient.is_heading (see
-- 004_recipe_ingredient_heading.sql) - a heading row reuses the existing
-- `instruction` column for its text and is excluded from step numbering
-- both in the PDF export and the detail view (CSS counter skip).
ALTER TABLE recipe_step
    ADD COLUMN is_heading TINYINT(1) NOT NULL DEFAULT 0 AFTER position;
