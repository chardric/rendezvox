-- 027: Add country_code to songs (artist origin, ISO 3166-1 alpha-2)
ALTER TABLE songs ADD COLUMN IF NOT EXISTS country_code CHAR(2) DEFAULT NULL;
CREATE INDEX IF NOT EXISTS idx_songs_country ON songs (country_code) WHERE country_code IS NOT NULL;
