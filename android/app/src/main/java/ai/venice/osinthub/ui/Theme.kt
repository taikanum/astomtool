package ai.venice.osinthub.ui

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.unit.sp

private val Dark = darkColorScheme(
    primary = Color(0xFF3B82F6), onPrimary = Color.White,
    background = Color(0xFF0B0F17), onBackground = Color(0xFFE8EEF7),
    surface = Color(0xFF141A24), onSurface = Color(0xFFE8EEF7),
    surfaceVariant = Color(0xFF1A2130), outline = Color(0xFF232C3A),
    secondary = Color(0xFF4ADE80), error = Color(0xFFF87171)
)

@Composable
fun HubTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = Dark,
        typography = Typography(
            bodyMedium = TextStyle(fontSize = 14.sp),
            labelSmall = TextStyle(fontSize = 11.sp)
        ),
        content = content
    )
}