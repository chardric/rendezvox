-- Seasonal date window for categories (MM-DD format, nullable = year-round)
ALTER TABLE categories
    ADD COLUMN IF NOT EXISTS seasonal_start VARCHAR(5) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS seasonal_end   VARCHAR(5) DEFAULT NULL;

COMMENT ON COLUMN categories.seasonal_start IS 'Season start as MM-DD (e.g. 09-01), NULL = year-round';
COMMENT ON COLUMN categories.seasonal_end   IS 'Season end as MM-DD (e.g. 01-06), NULL = year-round';

-- Christmas category: September 1 – January 6 (Filipino BER months + Three Kings)
-- Christian stays year-round (not seasonal)
UPDATE categories SET seasonal_start = '09-01', seasonal_end = '01-06' WHERE name = 'Christmas';
UPDATE categories SET seasonal_start = NULL, seasonal_end = NULL WHERE name = 'Christian';

-- Christmas title keywords for emergency auto-fill seasonal exclusion
-- Songs matching these patterns are excluded outside Sep 1 – Jan 6
INSERT INTO settings (key, value, type, description)
VALUES ('christmas_title_keywords', 'christmas,xmas,jingle bell,silent night,holy night,rudolph,noel,manger,bethlehem,mistletoe,deck the hall,feliz navidad,white christmas,little drummer,hark the herald,away in a manger,o come all ye,first noel,we wish you a merry,chestnuts roasting,let it snow,sleigh ride,frosty the snowman,winter wonderland,santa claus,santa baby,have yourself a merry,do you hear what i hear,o holy night,o christmas tree,o tannenbaum,12 days of christmas,twelve days of christmas,rockin around,carol of the bells,blue christmas,last christmas,all i want for christmas,mary did you know,go tell it on the mountain,angels we have heard,what child is this,it came upon a midnight,god rest ye merry,good king wenceslas,pasko,paskong,simbang gabi,noche buena,maligayang pasko,ang pasko,star ng pasko,himig ng pasko', 'string', 'Comma-separated Christmas title keywords for seasonal exclusion in emergency auto-fill')
ON CONFLICT (key) DO NOTHING;

INSERT INTO settings (key, value, type, description)
VALUES ('christmas_season_start', '09-01', 'string', 'Christmas season start date (MM-DD) for title-based exclusion')
ON CONFLICT (key) DO NOTHING;

INSERT INTO settings (key, value, type, description)
VALUES ('christmas_season_end', '01-06', 'string', 'Christmas season end date (MM-DD) for title-based exclusion')
ON CONFLICT (key) DO NOTHING;
