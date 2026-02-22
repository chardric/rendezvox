-- 004_seed_genres.sql — Seed standard music genres
-- Idempotent: ON CONFLICT DO NOTHING prevents duplicates

INSERT INTO categories (name, type) VALUES
  ('Rock','music'), ('Pop','music'), ('Country','music'),
  ('R&B','music'), ('Hip Hop','music'), ('Jazz','music'),
  ('Blues','music'), ('Classical','music'), ('Electronic','music'),
  ('Folk','music'), ('Reggae','music'), ('Latin','music'),
  ('Gospel','music'), ('Christian','music'), ('Metal','music'),
  ('Alternative','music'), ('Indie','music'), ('Funk','music'),
  ('Soul','music'), ('World','music'), ('Easy Listening','music'),
  ('New Age','music'), ('Disco','music'), ('Punk','music'),
  ('Singer-Songwriter','music')
ON CONFLICT (name) DO NOTHING;
