package ai.venice.osinthub.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
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
import ai.venice.osinthub.data.Prefs

@Composable
fun SettingsScreen() {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    val baseFlow by Prefs.baseUrl(ctx).collectAsState(initial = "")
    val keyFlow by Prefs.apiKey(ctx).collectAsState(initial = "")
    val intervalFlow by Prefs.interval(ctx).collectAsState(initial = 120)

    var base by remember { mutableStateOf(baseFlow) }
    var key by remember { mutableStateOf(keyFlow) }
    var interval by remember { mutableStateOf(intervalFlow.toString()) }
    var status by remember { mutableStateOf("") }

    Column(
        Modifier.fillMaxWidth().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)
    ) {
        Text("Settings", style = MaterialTheme.typography.headlineSmall)

        OutlinedTextField(
            value = base, onValueChange = { base = it },
            label = { Text("Hub URL (https://site/tracking.php)") },
            modifier = Modifier.fillMaxWidth(), singleLine = true
        )
        OutlinedTextField(
            value = key, onValueChange = { key = it },
            label = { Text("API key (optional)") },
            modifier = Modifier.fillMaxWidth(), singleLine = true
        )
        OutlinedTextField(
            value = interval, onValueChange = { interval = it },
            label = { Text("Beacon interval (seconds)") },
            modifier = Modifier.fillMaxWidth(), singleLine = true
        )

        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(onClick = {
                scope.launch {
                    Prefs.setBaseUrl(ctx, base)
                    Prefs.setApiKey(ctx, key)
                    Prefs.setInterval(ctx, interval.toIntOrNull() ?: 120)
                    status = "saved"
                }
            }) { Text("Save") }

            OutlinedButton(onClick = {
                scope.launch {
                    status = "testing..."
                    status = Api.test(ctx).fold({ "ok: $it" }, { "error: ${it.message}" })
                }
            }) { Text("Test") }
        }

        Text(status, style = MaterialTheme.typography.bodySmall)

        Text(
            "Create an API key on the hub dashboard (Keys tab). The app only needs read " +
                "scope unless you want it to create links.",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
    }
}