-- Fractions like 1/3 or 2/3 (photo import, schema.org import) were stored as
-- 0.333 / 0.667 - scaling such a recipe to 3 portions then gave 0.999 instead
-- of 1. Six decimals keep them exact enough that the client's 2-decimal
-- display rounding (formatAmount()) always lands on the right value.
ALTER TABLE recipe_ingredient MODIFY amount DECIMAL(12,6) NULL;
