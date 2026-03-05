-- Add is_christmas flag to songs for automatic seasonal exclusion
ALTER TABLE songs ADD COLUMN IF NOT EXISTS is_christmas BOOLEAN NOT NULL DEFAULT false;

-- Backfill existing songs using title keyword detection
UPDATE songs SET is_christmas = true
WHERE title ~* '\m(christmas|xmas|jingle bell|silent night|holy night|rudolph|noel|manger|bethlehem|mistletoe|deck the hall|feliz navidad|white christmas|little drummer|hark the herald|away in a manger|o come all ye|first noel|we wish you a merry|chestnuts roasting|let it snow|sleigh ride|frosty the snowman|winter wonderland|santa claus|santa baby|have yourself a merry|do you hear what i hear|o holy night|o christmas tree|o tannenbaum|12 days of christmas|twelve days of christmas|rockin around|carol of the bells|blue christmas|last christmas|all i want for christmas|mary did you know|go tell it on the mountain|angels we have heard|what child is this|it came upon a midnight|god rest ye merry|good king wenceslas|pasko|paskong|simbang gabi|noche buena|maligayang pasko|ang pasko|star ng pasko|himig ng pasko)';

-- Remove now-unused settings
DELETE FROM settings WHERE key IN ('christmas_title_keywords', 'christmas_season_start', 'christmas_season_end');

-- Index for efficient filtering
CREATE INDEX IF NOT EXISTS idx_songs_is_christmas ON songs (is_christmas) WHERE is_christmas = true;
