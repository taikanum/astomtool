package ai.venice.osinthub.ui.screens

import android.content.Context
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import ai.venice.osinthub.data.Api
import ai.venice.osinthub.data.Fmt
import ai.venice.osinthub.data.Prefs
import ai.venice.osinthub.svc.TrackerService

@Composable
fun HomeScreen() {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    val tracking by Prefs.tracking(ctx).collectAsState(initial = false)

    Column(
        Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)
    ) {
        Text("OSINT Hub", style = MaterialTheme.typography.headlineSmall)
        Text(
            "Your phone as a live tracker, plus a share target for instant lookups.",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )

        Card {
            Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                    Text("Background GPS beacon")
                    Switch(checked = tracking, onCheckedChange = { on ->
                        scope.launch {
                            Prefs.setTracking(ctx, on)
                            if (on) TrackerService.start(ctx) else TrackerService.stop(ctx)
                        }
                    })
                }
                Text(
                    "Posts raw fused location to the hub every interval, so a phone in a " +
                        "pocket shows up on the same map as browser visitors.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
        }

        Card {
            Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Quick checks")
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(onClick = { quick(ctx, scope, "ip", "ip", "") }) { Text("My IP") }
                    OutlinedButton(onClick = { quick(ctx, scope, "whois", "d", "example.com") }) { Text("example.com") }
                }
                Text("Result", style = MaterialTheme.typography.labelSmall)
                var out by androidx.compose.runtime.remember { androidx.compose.runtime.mutableStateOf("ready") }
                Text(out, style = MaterialTheme.typography.bodySmall)
                Button(onClick = {
                    scope.launch {
                        out = "running..."
                        val r = Api.get(ctx, "ip", mapOf("ip" to ""))
                        out = r.fold(
                            { el -> Fmt.summarise(el).joinToString("\n") { (k, v) -> "$k: $v" } },
                            { e -> "error: ${e.message}" }
                        )
                    }
                }) { Text("Run") }
            }
        }
    }
}

private fun quick(ctx: Context, scope: kotlinx.coroutines.CoroutineScope, route: String, k: String, v: String) {
    scope.launch { Api.get(ctx, route, mapOf(k to v)) }
}