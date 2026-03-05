-- 021: Add Ollama settings and AI provider selector
INSERT INTO settings (key, value) VALUES ('ollama_url', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO settings (key, value) VALUES ('ollama_model', 'qwen2.5:3b') ON CONFLICT (key) DO NOTHING;
INSERT INTO settings (key, value) VALUES ('ai_provider', 'gemini_ollama') ON CONFLICT (key) DO NOTHING;
