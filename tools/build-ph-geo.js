#!/usr/bin/env node

/**
 * build-ph-geo.js — Generate Philippine geographic hierarchy
 *
 * Fetches provinces, cities/municipalities, and barangays from the
 * PSGC Cloud API and geocodes city centers via Open-Meteo.
 *
 * Output: src/data/ph-geo.json
 *
 * Usage:
 *   node tools/build-ph-geo.js
 */

const fs = require('fs');
const path = require('path');
const https = require('https');

const PSGC_BASE = 'https://psgc.cloud/api';
const GEO_BASE = 'https://geocoding-api.open-meteo.com/v1/search';
const OUT_FILE = path.join(__dirname, '..', 'src', 'data', 'ph-geo.json');

// Rate-limit helpers
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function fetch(url) {
  return new Promise((resolve, reject) => {
    const mod = url.startsWith('https') ? https : require('http');
    mod.get(url, { headers: { 'User-Agent': 'RendezVox-geo-builder/1.0' } }, (res) => {
      if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
        return fetch(res.headers.location).then(resolve, reject);
      }
      let body = '';
      res.on('data', (c) => (body += c));
      res.on('end', () => {
        if (res.statusCode !== 200) {
          return reject(new Error(`HTTP ${res.statusCode}: ${url}\n${body.slice(0, 200)}`));
        }
        try { resolve(JSON.parse(body)); }
        catch (e) { reject(new Error(`JSON parse error for ${url}: ${e.message}`)); }
      });
      res.on('error', reject);
    }).on('error', reject);
  });
}

async function fetchWithRetry(url, retries = 5) {
  for (let i = 0; i < retries; i++) {
    try {
      return await fetch(url);
    } catch (err) {
      const is429 = err.message.includes('429');
      if (i === retries - 1) throw err;
      const delay = is429 ? 5000 * (i + 1) : 2000 * (i + 1);
      console.warn(`  Retry ${i + 1}/${retries} (wait ${delay}ms)${is429 ? ' [rate-limited]' : ''}`);
      await sleep(delay);
    }
  }
}

async function geocodeCity(cityName, provinceName) {
  const q = `${cityName}, ${provinceName}, Philippines`;
  const url = `${GEO_BASE}?${new URLSearchParams({ name: q, count: '1', format: 'json', language: 'en' })}`;
  try {
    const data = await fetchWithRetry(url);
    if (data && data.results && data.results.length > 0) {
      return [
        Math.round(data.results[0].latitude * 10000) / 10000,
        Math.round(data.results[0].longitude * 10000) / 10000,
      ];
    }
  } catch (e) {
    console.warn(`  Geocode failed for "${q}": ${e.message}`);
  }
  return null;
}

async function main() {
  console.log('Fetching provinces...');
  const provinces = await fetchWithRetry(`${PSGC_BASE}/provinces`);
  console.log(`  Found ${provinces.length} provinces`);

  // Load existing data to merge (fills in gaps from rate-limited runs)
  let result = {};
  if (fs.existsSync(OUT_FILE)) {
    try {
      result = JSON.parse(fs.readFileSync(OUT_FILE, 'utf8'));
      console.log(`  Loaded existing data with ${Object.keys(result).length} provinces (will fill gaps)`);
    } catch (e) {
      console.warn(`  Could not read existing file, starting fresh`);
    }
  }

  let totalCities = 0;
  let totalBarangays = 0;
  let skipped = 0;

  for (let pi = 0; pi < provinces.length; pi++) {
    const prov = provinces[pi];
    const provName = prov.name;

    // Skip provinces we already have
    if (result[provName] && Object.keys(result[provName]).length > 0) {
      const nc = Object.keys(result[provName]).length;
      totalCities += nc;
      Object.values(result[provName]).forEach((c) => { totalBarangays += (c.b || []).length; });
      skipped++;
      continue;
    }

    console.log(`[${pi + 1}/${provinces.length}] ${provName}`);

    // Fetch cities/municipalities for this province
    let cities;
    try {
      cities = await fetchWithRetry(`${PSGC_BASE}/provinces/${prov.code}/cities-municipalities`);
    } catch (e) {
      console.warn(`  Failed to fetch cities for ${provName}: ${e.message}`);
      continue;
    }

    if (!cities || cities.length === 0) {
      console.log(`  No cities found, skipping`);
      continue;
    }

    result[provName] = {};

    // Process cities in parallel batches of 3
    const BATCH = 3;
    for (let bi = 0; bi < cities.length; bi += BATCH) {
      const batch = cities.slice(bi, bi + BATCH);
      await Promise.all(batch.map(async (city) => {
        const cityName = city.name;

        // Fetch barangays
        let barangays = [];
        try {
          barangays = await fetchWithRetry(
            `${PSGC_BASE}/cities-municipalities/${city.code}/barangays`
          );
        } catch (e) {
          console.warn(`  Failed to fetch barangays for ${cityName}: ${e.message}`);
        }

        // Geocode city center
        const coords = await geocodeCity(cityName, provName);

        const brgyNames = (barangays || []).map((b) => b.name).sort();

        result[provName][cityName] = {
          c: coords || [0, 0],
          b: brgyNames,
        };

        totalCities++;
        totalBarangays += brgyNames.length;
      }));

      if (bi + BATCH < cities.length) await sleep(300); // pause between batches (avoid 429)
      const done = Math.min(bi + BATCH, cities.length);
      if (done % 10 === 0 || done === cities.length) {
        console.log(`  ${done}/${cities.length} cities processed`);
      }
    }

    // Small delay between provinces
    await sleep(50);
  }

  // Sort provinces alphabetically
  const sorted = {};
  Object.keys(result).sort().forEach((k) => {
    // Sort cities within each province
    const cities = {};
    Object.keys(result[k]).sort().forEach((ck) => {
      cities[ck] = result[k][ck];
    });
    sorted[k] = cities;
  });

  fs.writeFileSync(OUT_FILE, JSON.stringify(sorted));

  const sizeKB = Math.round(fs.statSync(OUT_FILE).size / 1024);
  console.log(`\nDone! ${Object.keys(sorted).length} provinces, ${totalCities} cities, ${totalBarangays} barangays` +
    (skipped ? ` (${skipped} provinces reused from cache)` : ''));
  console.log(`Output: ${OUT_FILE} (${sizeKB} KB)`);
}

main().catch((err) => {
  console.error('Fatal error:', err);
  process.exit(1);
});
