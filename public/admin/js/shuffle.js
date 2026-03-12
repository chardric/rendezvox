/* ============================================================
   RendezVox — Shuffle & Separation Utilities
   Shared across all admin pages that need playlist shuffling.
   ============================================================ */
var RendezVoxShuffle = (function() {
  'use strict';

  /**
   * Fisher-Yates shuffle (in-place).
   */
  function fisherYates(arr) {
    for (var i = arr.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var tmp = arr[i]; arr[i] = arr[j]; arr[j] = tmp;
    }
    return arr;
  }

  /**
   * Generic separation enforcer. Ensures no two songs with the same
   * value for `field` (or `keyFn` result) appear within `minGap` positions.
   * Multi-pass (up to 3) for cascading resolution.
   *
   * @param {Array}    songs  Song objects.
   * @param {number}   minGap Minimum gap between matches.
   * @param {Function} keyFn  Function(song) -> string/number key to compare.
   * @param {Object}   [prev] Previous song to check position 0 against.
   */
  function enforceSeparation(songs, minGap, keyFn, prev) {
    var len = songs.length;
    if (len <= 1) return songs;
    minGap = Math.min(minGap, Math.floor(len / 2));
    if (minGap < 1) return songs;

    for (var pass = 0; pass < 3; pass++) {
      var swapped = false;
      for (var i = 0; i < len; i++) {
        var key = keyFn(songs[i]);
        var collision = false;

        // Check previous minGap positions
        for (var back = 1; back <= minGap; back++) {
          var pi = i - back;
          if (pi >= 0) {
            if (keyFn(songs[pi]) === key) { collision = true; break; }
          } else if (pi === -1 && prev && keyFn(prev) === key) {
            collision = true; break;
          }
        }
        if (!collision) continue;

        // Find best swap candidate further in list
        var bestJ = -1;
        for (var j = i + 1; j < len; j++) {
          var jKey = keyFn(songs[j]);
          var ok = true;
          // Would songs[j] collide at position i?
          for (var b = 1; b <= minGap && ok; b++) {
            var p = i - b;
            if (p >= 0 && keyFn(songs[p]) === jKey) ok = false;
            else if (p === -1 && prev && keyFn(prev) === jKey) ok = false;
          }
          // Would songs[i] collide at position j?
          for (var b2 = 1; b2 <= minGap && ok; b2++) {
            var p2 = j - b2;
            if (p2 >= 0 && p2 !== i && keyFn(songs[p2]) === key) ok = false;
          }
          // Also check forward from j
          for (var b3 = 1; b3 <= minGap && ok; b3++) {
            var p3 = j + b3;
            if (p3 < len && songs[p3] && keyFn(songs[p3]) === key) ok = false;
          }
          if (ok) { bestJ = j; break; }
        }

        if (bestJ >= 0) {
          var t = songs[i]; songs[i] = songs[bestJ]; songs[bestJ] = t;
          swapped = true;
        }
      }
      if (!swapped) break;
    }
    return songs;
  }

  /** Strip rendition suffixes to get base title for comparison. */
  function baseTitle(title) {
    if (!title) return '';
    var s = title.toLowerCase();
    // Remove parenthesized/bracketed content
    s = s.replace(/\s*[\(\[][^\)\]]*[\)\]]\s*/g, ' ');
    // Remove dash-separated rendition suffixes
    s = s.replace(/\s+[-–—]\s+(?:.*\b(?:remix|acoustic|live|radio|club|extended|instrumental|unplugged|remaster(?:ed)?|version|ver\.|mix|edit|dub|demo|karaoke|stripped|deluxe|bonus|original|alternate|alt\.|cover|reprise|interlude|orchestral|symphony|piano|guitar|vocal).*)$/i, '');
    // Remove trailing bare rendition keywords
    s = s.replace(/\s+(?:remix|acoustic|live|radio|club|extended|instrumental|unplugged|remaster(?:ed)?|version|mix|edit|dub|demo|karaoke|stripped|cover)$/i, '');
    // Remove "feat./ft." and everything after
    s = s.replace(/\s+(?:feat\.?|ft\.?)\s+.*/i, '');
    return s.trim();
  }

  /**
   * Enforce artist separation (minGap default 6).
   */
  function enforceArtistSeparation(songs, minGap, prev) {
    return enforceSeparation(songs, minGap || 6, function(s) { return s.artist_id; }, prev);
  }

  /**
   * Enforce title separation — no same base title within minGap (default 2).
   */
  function enforceTitleSeparation(songs, minGap, prev) {
    return enforceSeparation(songs, minGap || 2, function(s) { return baseTitle(s.title); }, prev);
  }

  /**
   * Full shuffle pipeline: Fisher-Yates + artist separation + title separation.
   */
  function shuffle(songs, minGap, prev) {
    fisherYates(songs);
    enforceArtistSeparation(songs, minGap || 6, prev);
    enforceTitleSeparation(songs, 3, prev);
    return songs;
  }

  return {
    fisherYates: fisherYates,
    enforceArtistSeparation: enforceArtistSeparation,
    enforceTitleSeparation: enforceTitleSeparation,
    shuffle: shuffle
  };
})();
