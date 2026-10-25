package ai.venice.osinthub.data

import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull

/** Human-readable summary rows for the most common OSINT payloads. */
object Fmt {
    fun summarise(el: JsonElement): List<Pair<String, String>> {
        val o = el as? JsonObject ?: return listOf("raw" to el.toString())
        val out = mutableListOf<Pair<String, String>>()
        fun s(k: String) = (o[k] as? JsonPrimitive)?.contentOrNull
        s("email")?.let { out += "email" to it }
        s("e164")?.let { out += "number" to it }
        s("ip")?.let { out += "ip" to it }
        s("username")?.let { out += "handle" to it }
        s("domain")?.let { out += "domain" to it }
        (o["risk"] as? JsonObject)?.let { r ->
            out += "risk" to ((r["score"] as? JsonPrimitive)?.content ?: "?") + "/100 " +
                ((r["level"] as? JsonPrimitive)?.content ?: "")
        }
        (o["spam"] as? JsonObject)?.let { r ->
            out += "spam" to ((r["score"] as? JsonPrimitive)?.content ?: "?") + "/100"
        }
        (o["geo"] as? JsonObject)?.let { g ->
            val city = (g["city"] as? JsonPrimitive)?.contentOrNull
            val country = (g["country"] as? JsonPrimitive)?.contentOrNull
            if (city != null || country != null) out += "location" to listOfNotNull(city, country).joinToString(", ")
            (g["isp"] as? JsonPrimitive)?.contentOrNull?.let { out += "isp" to it }
        }
        (o["asn"] as? JsonObject)?.let { a ->
            (a["number"] as? JsonPrimitive)?.contentOrNull?.let {
                out += "asn" to "AS" + it + " " + ((a["name"] as? JsonPrimitive)?.contentOrNull ?: "")
            }
        }
        (o["breaches"] as? JsonArray)?.let { out += "breaches" to it.size.toString() }
        (o["accounts"] as? JsonArray)?.let { out += "accounts" to it.size.toString() }
        (o["profiles"] as? JsonArray)?.let { out += "profiles found" to it.size.toString() }
        (o["found"] as? JsonPrimitive)?.contentOrNull?.let { out += "sites hit" to it }
        (o["count"] as? JsonPrimitive)?.contentOrNull?.let { out += "count" to it }
        (o["tor"] as? JsonPrimitive)?.let { out += "tor exit" to it.content }
        (o["blacklisted"] as? JsonPrimitive)?.let { out += "blacklisted" to it.content }
        (o["days_left"] as? JsonPrimitive)?.contentOrNull?.let { out += "cert days left" to it }
        (o["grade"] as? JsonPrimitive)?.contentOrNull?.let { out += "grade" to it }
        (o["pwned"] as? JsonPrimitive)?.let { out += "pwned" to (if (it.content == "true") "YES" else "no") }
        (o["disposable"] as? JsonPrimitive)?.let { out += "disposable" to it.content }
        (o["registrar"] as? JsonPrimitive)?.contentOrNull?.let { out += "registrar" to it }
        return out.ifEmpty { listOf("result" to "ok") }
    }
}