-- todo.md "TL/EL beim Import immer groß": imports now always write EL/TL
-- (UnitNormalizer), this brings ingredients saved before that in line -
-- any spelling of el/tl ("el", "El.", "tl." ...) becomes EL/TL. BINARY so
-- the check isn't fooled by the column's case-insensitive collation.
UPDATE recipe_ingredient
SET unit = 'EL'
WHERE unit IS NOT NULL
  AND LOWER(TRIM(TRAILING '.' FROM TRIM(unit))) = 'el'
  AND BINARY unit <> 'EL';

UPDATE recipe_ingredient
SET unit = 'TL'
WHERE unit IS NOT NULL
  AND LOWER(TRIM(TRAILING '.' FROM TRIM(unit))) = 'tl'
  AND BINARY unit <> 'TL';
