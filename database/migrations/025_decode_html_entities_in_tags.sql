-- todo.md "Encoding-Probleme beim Rezeptimport", tags: same entity
-- replacement as 024 for tag names. tag.name is unique (case-insensitive
-- collation), so "K&uuml;rbis" may decode to a tag that already exists
-- ("Kürbis") - such tags are merged: the recipes move to the keeper (the
-- existing clean tag, else the lowest id among the decoded ones), the
-- duplicate is deleted, then the keeper gets the decoded name.
CREATE TABLE tag_entity_fix (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    new_name VARCHAR(50) NOT NULL,
    keeper_id INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO tag_entity_fix (id, new_name)
SELECT id, TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(name, '&auml;', 'ä'), '&ouml;', 'ö'), '&uuml;', 'ü'), '&Auml;', 'Ä'), '&Ouml;', 'Ö'), '&Uuml;', 'Ü'), '&szlig;', 'ß'), '&eacute;', 'é'), '&egrave;', 'è'), '&ecirc;', 'ê'), '&agrave;', 'à'), '&acirc;', 'â'), '&icirc;', 'î'), '&ocirc;', 'ô'), '&ccedil;', 'ç'), '&ntilde;', 'ñ'), '&#252;', 'ü'), '&#246;', 'ö'), '&#228;', 'ä'), '&#220;', 'Ü'), '&#214;', 'Ö'), '&#196;', 'Ä'), '&#223;', 'ß'), '&nbsp;', ' '), '&quot;', '"'), '&#39;', ''''), '&frac12;', '½'), '&frac14;', '¼'), '&frac34;', '¾'), '&deg;', '°'), '&amp;', '&'))
FROM tag
WHERE name LIKE '%&%;%';

-- keeper: an existing tag without entities that already has the name ...
UPDATE tag_entity_fix f
JOIN tag t ON t.name = f.new_name AND t.id NOT IN (SELECT id FROM (SELECT id FROM tag_entity_fix) x)
SET f.keeper_id = t.id;

-- ... otherwise the lowest id among the tags decoding to that name.
UPDATE tag_entity_fix f
JOIN (SELECT new_name, MIN(id) AS min_id FROM (SELECT id, new_name FROM tag_entity_fix) y GROUP BY new_name) m ON m.new_name = f.new_name
SET f.keeper_id = m.min_id
WHERE f.keeper_id IS NULL;

INSERT IGNORE INTO recipe_tag (recipe_id, tag_id)
SELECT rt.recipe_id, f.keeper_id
FROM recipe_tag rt
JOIN tag_entity_fix f ON f.id = rt.tag_id
WHERE f.keeper_id <> f.id;

-- recipe_tag rows of the duplicates go with them (ON DELETE CASCADE).
DELETE t FROM tag t
JOIN tag_entity_fix f ON f.id = t.id
WHERE f.keeper_id <> f.id;

UPDATE tag t
JOIN tag_entity_fix f ON f.id = t.id AND f.keeper_id = f.id
SET t.name = f.new_name;

DROP TABLE tag_entity_fix;
