<?php

declare(strict_types=1);

/**
 * Content filter for listener-submitted text (names, dedication messages).
 *
 * Checks for profanity (English + Filipino + Ilocano), l33t speak variants,
 * stretched/repeated characters, ALL CAPS spam, and admin-defined
 * custom blocked words.
 */
class ContentFilter
{
    private array $wordList;

    /** L33t speak substitution map (regex-safe) */
    private const LEET_MAP = [
        '@' => 'a',
        '4' => 'a',
        '3' => 'e',
        '1' => 'i',
        '!' => 'i',
        '0' => 'o',
        '$' => 's',
        '5' => 's',
        '7' => 't',
        '+' => 't',
    ];

    /** Built-in English profanity */
    private const ENGLISH_WORDS = [
        'fuck', 'shit', 'ass', 'asshole', 'bitch', 'bastard',
        'damn', 'dick', 'cock', 'cunt', 'piss', 'whore',
        'slut', 'fag', 'faggot', 'nigger', 'nigga', 'retard',
        'motherfucker', 'bullshit', 'wanker', 'twat', 'prick',
        'douche', 'douchebag', 'jackass',
    ];

    /** Built-in Filipino (Tagalog) profanity */
    private const FILIPINO_WORDS = [
        'putangina', 'tangina', 'gago', 'gaga', 'tanga',
        'bobo', 'boba', 'tarantado', 'tarantada', 'ulol',
        'ungas', 'kupal', 'pakyu', 'pakshet', 'punyeta',
        'leche', 'lintik', 'hinayupak', 'hayop', 'peste',
        'bwisit', 'siraulo', 'inutil', 'kingina', 'kinginamo',
        'tangamo', 'puta', 'pota', 'potangina', 'ampota',
    ];

    /** Built-in Ilocano profanity */
    private const ILOCANO_WORDS = [
        // Primary expletives
        'ukininam', 'ukinnam', 'ukinam', 'uki', 'ukim',
        // Sexual terms
        'iyot', 'agiyot', 'salsal', 'agsalsal',
        'chupa', 'chupaen', 'uttog', 'umuttog',
        // Vulgar anatomy
        'buto', 'butok', 'latig', 'lukdit', 'lotdit', 'lusi',
        'muting', 'pipit', 'bagaas', 'ubet',
        // Insults
        'bagtit', 'agbagtit', 'toree', 'lastog',
        'sayet', 'nagsayet',
        // Appearance/hygiene insults
        'galas', 'nagalas', 'bangsit', 'nagbangsit', 'nabangsit',
        // Vulgar misc
        'tae', 'tutua', 'naglawa',
    ];

    public function __construct(?PDO $db = null)
    {
        $this->wordList = array_merge(self::ENGLISH_WORDS, self::FILIPINO_WORDS, self::ILOCANO_WORDS);

        if ($db) {
            $custom = $this->loadCustomWords($db);
            if ($custom) {
                $this->wordList = array_merge($this->wordList, $custom);
            }
        }
    }

    /**
     * Check text for offensive content.
     *
     * @return string|null Error message if blocked, null if clean
     */
    public function check(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // ── Spam detection ──────────────────────────────

        // ALL CAPS (8+ chars, ignoring spaces/punctuation)
        $alphaOnly = preg_replace('/[^a-zA-Z]/', '', $text);
        if (mb_strlen($alphaOnly) >= 8 && $alphaOnly === mb_strtoupper($alphaOnly)) {
            return 'Please don\'t use all capital letters';
        }

        // Excessive repeated characters (6+ of same char)
        if (preg_match('/(.)\1{5,}/u', $text)) {
            return 'Message contains too many repeated characters';
        }

        // ── Profanity detection ─────────────────────────

        // Check two normalized forms:
        // 1. With l33t substitution — catches "g@g0", "$h1t", "f*ck"
        // 2. Without l33t — catches "gago123" (l33t would turn "123" → "i2e" hiding boundary)
        $withLeet    = $this->normalize($text, true);
        $withoutLeet = $this->normalize($text, false);

        foreach ($this->wordList as $word) {
            $pattern = '/(?<![a-z])' . preg_quote($word, '/') . '(?![a-z])/i';
            if (preg_match($pattern, $withLeet) || preg_match($pattern, $withoutLeet)) {
                return 'Message contains inappropriate language';
            }
        }

        return null;
    }

    /**
     * Normalize text for profanity matching:
     * - Strip invisible/zero-width Unicode characters
     * - Apply l33t speak substitutions
     * - Collapse repeated characters
     * - Lowercase
     */
    private function normalize(string $text, bool $applyLeet = true): string
    {
        // Strip zero-width and invisible Unicode chars
        $text = preg_replace('/[\x{200B}-\x{200F}\x{2028}-\x{202F}\x{2060}-\x{2069}\x{FEFF}]/u', '', $text);

        // Strip common separator chars used to break up words: dots, dashes, underscores, asterisks
        $text = preg_replace('/[.\-_*]/', '', $text);

        // Lowercase
        $text = mb_strtolower($text);

        // L33t speak substitutions
        if ($applyLeet) {
            foreach (self::LEET_MAP as $leet => $letter) {
                $text = str_replace((string) $leet, $letter, $text);
            }
        }

        // Collapse repeated characters (3+ of same char → single char)
        $text = preg_replace('/(.)\1{2,}/u', '$1', $text);

        return $text;
    }

    /**
     * Load admin-configured custom blocked words from settings.
     *
     * @return string[] Additional words to block
     */
    private function loadCustomWords(PDO $db): array
    {
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute(['key' => 'profanity_custom_words']);
        $val = $stmt->fetchColumn();

        if ($val === false || trim((string) $val) === '') {
            return [];
        }

        $words = array_map('trim', explode(',', (string) $val));
        return array_filter($words, fn(string $w) => $w !== '');
    }
}
