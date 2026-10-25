package ai.venice.osinthub.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import ai.venice.osinthub.data.Api
import ai.venice.osinthub.data.Fmt

private val ROUTES = listOf("email", "phone", "ip", "user", "whois", "dns", "subs", "tls", "headers", "pass")

@Composable
fun ToolsScreen(prefill: String?, prefillKind: String?, onConsumed: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var route by remember { mutableStateOf(prefillKind ?: "email") }
    var value by remember { mutableStateOf(prefill ?: "") }
    var out by remember { mutableStateOf("ready") }
    var busy by remember { mutableStateOf(false) }

    LaunchedEffect(prefill) { if (prefill != null) onConsumed() }

    Column(
        Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp)
    ) {
        Text("OSINT tools", style = MaterialTheme.typography.headlineSmall)
        Text("Shared text is auto-detected and pre-filled.", style = MaterialTheme.typography.bodySmall)

        Row(Modifier.fillMaxWidth().horizontalScrollCompat(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            ROUTES.forEach { r ->
                FilterChip(selected = route == r, onClick = { route = r }, label = { Text(r) })
            }
        }

        OutlinedTextField(
            value = value,
            onValueChange = { value = it },
            label = { Text("value") },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true
        )

        Button(
            enabled = !busy && value.isNotBlank(),
            onClick = {
                scope.launch {
                    busy = true; out = "running..."
                    val params = when (route) {
                        "email" -> mapOf("e" to value, "deep" to "1")
                        "phone" -> mapOf("n" to value, "deep" to "1")
                        "ip" -> mapOf("ip" to value, "deep" to "1")
                        "user" -> mapOf("u" to value)
                        "whois", "dns", "subs", "tls", "headers" -> mapOf("d" to value)
                        else -> mapOf("p" to value)
                    }
                    out = Api.get(ctx, route, params).fold(
                        { el -> Fmt.summarise(el).joinToString("\n") { (k, v) -> "$k: $v" } + "\n\n" + el.toString().take(4000) },
                        { e -> "error: ${e.message}" }
                    )
                    busy = false
                }
            }
        ) { Text("Run") }

        Text(out, style = MaterialTheme.typography.bodySmall)
    }
}

@Composable
private fun Modifier.horizontalScrollCompat(): Modifier = this