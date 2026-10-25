package ai.venice.osinthub.data

import android.content.Context
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.net.URLEncoder
import java.util.concurrent.TimeUnit

object Api {
    private val client = OkHttpClient.Builder()
        .connectTimeout(12, TimeUnit.SECONDS)
        .readTimeout(40, TimeUnit.SECONDS)
        .build()
    val json = Json { ignoreUnknownKeys = true; isLenient = true }

    private suspend fun base(c: Context) = Prefs.baseUrl(c).first()
    private suspend fun key(c: Context) = Prefs.apiKey(c).first()

    private fun build(base: String, params: Map<String, String>): String {
        val q = params.entries.filter { it.value.isNotBlank() }
            .joinToString("&") { it.key + "=" + URLEncoder.encode(it.value, "UTF-8") }
        return base + "?" + q
    }

    suspend fun get(c: Context, t: String, params: Map<String, String> = emptyMap()): Result<JsonElement> =
        withContext(Dispatchers.IO) {
            val b = base(c)
            if (b.isBlank()) return@withContext Result.failure(IllegalStateException("Set the hub URL in Settings"))
            val k = key(c)
            val all = params.toMutableMap().apply { put("i", "1"); put("t", t); if (k.isNotBlank()) put("key", k) }
            try {
                val req = Request.Builder().url(build(b, all)).header("Accept", "application/json").build()
                client.newCall(req).execute().use { r ->
                    val body = r.body?.string() ?: ""
                    if (!r.isSuccessful) return@withContext Result.failure(
                        IllegalStateException("HTTP " + r.code + ": " + body.take(180)))
                    Result.success(json.parseToJsonElement(body))
                }
            } catch (e: Exception) { Result.failure(e) }
        }

    suspend fun post(c: Context, t: String, body: String): Result<JsonElement> =
        withContext(Dispatchers.IO) {
            val b = base(c)
            if (b.isBlank()) return@withContext Result.failure(IllegalStateException("Set the hub URL"))
            try {
                val req = Request.Builder().url(build(b, mapOf("i" to "1", "t" to t)))
                    .post(body.toRequestBody("application/json".toMediaType())).build()
                client.newCall(req).execute().use { r ->
                    Result.success(json.parseToJsonElement(r.body?.string() ?: "{}"))
                }
            } catch (e: Exception) { Result.failure(e) }
        }

    suspend fun test(c: Context): Result<String> = get(c, "v1").map { el ->
        val o = el as? JsonObject
        "connected - " + (o?.get("app")?.jsonPrimitive?.content ?: "ok")
    }
}