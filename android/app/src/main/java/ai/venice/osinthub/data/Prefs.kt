package ai.venice.osinthub.data

import android.content.Context
import androidx.datastore.preferences.core.booleanPreferencesKey
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.intPreferencesKey
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map

private val Context.ds by preferencesDataStore("hub")

object Prefs {
    private val BASE = stringPreferencesKey("base_url")
    private val KEY = stringPreferencesKey("api_key")
    private val TRACKING = booleanPreferencesKey("tracking")
    private val INTERVAL = intPreferencesKey("interval_sec")

    fun baseUrl(c: Context): Flow<String> = c.ds.data.map { it[BASE] ?: "" }
    fun apiKey(c: Context): Flow<String> = c.ds.data.map { it[KEY] ?: "" }
    fun tracking(c: Context): Flow<Boolean> = c.ds.data.map { it[TRACKING] ?: false }
    fun interval(c: Context): Flow<Int> = c.ds.data.map { it[INTERVAL] ?: 120 }

    suspend fun setBaseUrl(c: Context, v: String) = c.ds.edit { it[BASE] = v.trim().trimEnd('?', '/') }
    suspend fun setApiKey(c: Context, v: String) = c.ds.edit { it[KEY] = v.trim() }
    suspend fun setTracking(c: Context, v: Boolean) = c.ds.edit { it[TRACKING] = v }
    suspend fun setInterval(c: Context, v: Int) = c.ds.edit { it[INTERVAL] = v }
}