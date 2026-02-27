package net.downstreamtech.rendezvox.data

import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import okhttp3.*
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.BufferedReader
import java.io.IOException
import java.io.InputStreamReader
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

class RadioApi(private val baseUrl: String) {

    private val client = OkHttpClient.Builder()
        .connectTimeout(10, java.util.concurrent.TimeUnit.SECONDS)
        .readTimeout(30, java.util.concurrent.TimeUnit.SECONDS)
        .build()

    private val gson = Gson()

    val streamUrl: String
        get() = "$baseUrl/stream/live"

    val icecastBaseUrl: String
        get() = "$baseUrl/stream"

    suspend fun fetchConfig(): StationConfig = withContext(Dispatchers.IO) {
        val request = Request.Builder().url("$baseUrl/api/config")
            .cacheControl(CacheControl.FORCE_NETWORK).build()
        try {
            val response = client.newCall(request).await()
            val body = response.body?.string() ?: "{}"
            gson.fromJson(body, StationConfig::class.java)
        } catch (e: Exception) {
            StationConfig()
        }
    }

    suspend fun fetchNowPlaying(): NowPlayingData? = withContext(Dispatchers.IO) {
        val request = Request.Builder().url("$baseUrl/api/now-playing").build()
        try {
            val response = client.newCall(request).await()
            val body = response.body?.string() ?: return@withContext null
            gson.fromJson(body, NowPlayingData::class.java)
        } catch (e: Exception) {
            null
        }
    }

    suspend fun fetchListenerCount(): Int = withContext(Dispatchers.IO) {
        val request = Request.Builder().url("$icecastBaseUrl/status-json.xsl").build()
        try {
            val response = client.newCall(request).await()
            val body = response.body?.string() ?: return@withContext 0
            val json = gson.fromJson(body, Map::class.java)
            val icestats = json["icestats"] as? Map<*, *> ?: return@withContext 0
            val source = icestats["source"] ?: return@withContext 0

            when (source) {
                is Map<*, *> -> ((source["listeners"] as? Number)?.toInt() ?: 0)
                is List<*> -> {
                    val mount = source.filterIsInstance<Map<*, *>>()
                        .find { (it["listenurl"] as? String)?.contains("/live") == true }
                    (mount?.get("listeners") as? Number)?.toInt() ?: 0
                }
                else -> 0
            }
        } catch (e: Exception) {
            0
        }
    }

    suspend fun fetchRecentPlays(): List<RecentPlay> = withContext(Dispatchers.IO) {
        val request = Request.Builder().url("$baseUrl/api/recent-plays").build()
        try {
            val response = client.newCall(request).await()
            val body = response.body?.string() ?: return@withContext emptyList()
            val result = gson.fromJson(body, RecentPlaysResponse::class.java)
            result.plays
        } catch (_: Exception) {
            emptyList()
        }
    }

    suspend fun searchSong(title: String, artist: String? = null): SearchResult =
        withContext(Dispatchers.IO) {
            var url = "$baseUrl/api/search-song?title=${java.net.URLEncoder.encode(title, "UTF-8")}"
            if (!artist.isNullOrBlank()) {
                url += "&artist=${java.net.URLEncoder.encode(artist, "UTF-8")}"
            }
            val request = Request.Builder().url(url).build()
            try {
                val response = client.newCall(request).await()
                val body = response.body?.string() ?: return@withContext SearchResult()
                gson.fromJson(body, SearchResult::class.java)
            } catch (e: Exception) {
                SearchResult()
            }
        }

    suspend fun submitRequest(requestBody: RequestBody): Pair<Int, RequestResponse> =
        withContext(Dispatchers.IO) {
            val json = gson.toJson(requestBody)
            val body = json.toRequestBody("application/json".toMediaType())
            val request = Request.Builder()
                .url("$baseUrl/api/request")
                .post(body)
                .build()
            try {
                val response = client.newCall(request).await()
                val responseBody = response.body?.string() ?: "{}"
                val data = gson.fromJson(responseBody, RequestResponse::class.java)
                Pair(response.code, data)
            } catch (e: Exception) {
                Pair(0, RequestResponse(error = "Network error"))
            }
        }

    /**
     * Connect to the SSE endpoint for real-time now-playing updates.
     * Calls [onEvent] on each "now-playing" event with the parsed data.
     * Blocks the calling coroutine until cancelled.
     */
    suspend fun connectSSE(onEvent: (NowPlayingData) -> Unit): Unit =
        withContext(Dispatchers.IO) {
            val request = Request.Builder()
                .url("$baseUrl/api/sse/now-playing")
                .header("Accept", "text/event-stream")
                .build()

            try {
                val response = client.newCall(request).await()
                val reader = BufferedReader(InputStreamReader(response.body!!.byteStream()))
                var eventType: String? = null
                val dataBuilder = StringBuilder()

                while (true) {
                    val line = reader.readLine() ?: break

                    when {
                        line.startsWith("event:") -> {
                            eventType = line.removePrefix("event:").trim()
                        }
                        line.startsWith("data:") -> {
                            dataBuilder.append(line.removePrefix("data:").trim())
                        }
                        line.isEmpty() && dataBuilder.isNotEmpty() -> {
                            if (eventType == "now-playing") {
                                try {
                                    val data = gson.fromJson(
                                        dataBuilder.toString(),
                                        NowPlayingData::class.java
                                    )
                                    onEvent(data)
                                } catch (_: Exception) {}
                            }
                            eventType = null
                            dataBuilder.clear()
                        }
                    }
                }
            } catch (_: Exception) {
                // Connection lost — caller should reconnect
            }
        }

    private suspend fun Call.await(): Response = suspendCancellableCoroutine { cont ->
        enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                if (!cont.isCancelled) cont.resumeWithException(e)
            }
            override fun onResponse(call: Call, response: Response) {
                cont.resume(response)
            }
        })
        cont.invokeOnCancellation { cancel() }
    }
}
