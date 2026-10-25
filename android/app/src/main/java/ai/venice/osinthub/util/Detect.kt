package ai.venice.osinthub.util

import java.util.regex.Pattern

private val EMAIL = Pattern.compile("""[\w.+-]+@[\w-]+\.[\w.-]+""")
private val IP = Pattern.compile("""\b(\d{1,3}\.){3}\d{1,3}\b""")
private val PHONE = Pattern.compile("""\+?\d[\d\s\-().]{6,}\d""")
private val DOMAIN = Pattern.compile("""\b([a-z0-9-]+\.)+[a-z]{2,}\b""", Pattern.CASE_INSENSITIVE)

/** Returns a hub route + value for a shared blob of text, or null. */
fun detect(text: String): Pair<String, String>? {
    val t = text.trim()
    if (t.isEmpty()) return null
    EMAIL.matcher(t).let { m -> if (m.find()) return "email" to m.group() }
    IP.matcher(t).let { m -> if (m.find()) return "ip" to m.group() }
    if (t.startsWith("http")) DOMAIN.matcher(t).let { m -> if (m.find()) return "domain" to m.group() }
    PHONE.matcher(t).let { m -> if (m.find() && m.group().count { it.isDigit() } >= 7) return "phone" to m.group() }
    DOMAIN.matcher(t).let { m -> if (m.find()) return "domain" to m.group() }
    if (t.matches(Regex("""[A-Za-z0-9._-]{2,40}"""))) return "user" to t
    return null
}