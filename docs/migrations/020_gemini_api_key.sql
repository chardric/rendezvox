-- 020: Add Gemini API key setting for AI metadata enrichment
INSERT INTO settings (key, value) VALUES ('gemini_api_key', '')
ON CONFLICT (key) DO NOTHING;
