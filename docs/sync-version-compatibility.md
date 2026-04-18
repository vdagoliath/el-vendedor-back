# Flexibilizar la validación de versiones en la sincronización

El backend aplica dos validaciones al recibir un request de sync:

1. **Versión del protocolo** (header `X-Sync-Version`) — la habla el código del cliente/servidor y solo cambia cuando se rompe el contrato de payload.
2. **Versión de la app cliente** (header `X-Client-App-Version` o `device.app_version`) — controlada por política configurable en `.env`.

Ambas se evalúan en [`App\Support\Sync\SyncCompatibility::evaluateRequest()`](../app/Support/Sync/SyncCompatibility.php) y se conectan vía el middleware `sync.request`.

---

## Opciones (de más a menos permisiva)

### 1. Desactivar completamente la validación de versión de app

En el `.env` del backend:

```env
SYNC_APP_COMPATIBILITY_POLICY=none
```

Con `none`, `isAppPolicyEnforced()` retorna `false` y `evaluateRequest()` sale antes de comparar versiones — sigue validando el protocolo pero ignora completamente la versión de la app. Es el switch principal para desarrollo.

### 2. Política de versión mínima (recomendado para producción)

```env
SYNC_APP_COMPATIBILITY_POLICY=minimum_version
SYNC_MINIMUM_APP_VERSION=1.0.0
```

Acepta cualquier versión `>= 1.0.0`. Los vendedores no quedan atrapados por un número de build exacto. La comparación usa `version_compare()` de PHP — soporta semver estándar (`1.0.0`, `1.2.3-beta`, etc).

### 3. Lista explícita

```env
SYNC_APP_COMPATIBILITY_POLICY=explicit_list
SYNC_COMPATIBLE_APP_VERSIONS=1.2.0,1.2.1,1.3.0-beta
```

Solo esas versiones sincronizan. Útil si querés habilitar un beta específico sin subir el mínimo global.

### 4. Ampliar versiones de protocolo soportadas

Si más adelante subís el protocolo a `2` pero querés seguir aceptando clientes `1`:

```env
SYNC_PROTOCOL_VERSION=2
SYNC_SUPPORTED_PROTOCOL_VERSIONS=1,2
```

`supported_protocol_versions` es una lista separada por comas. El cliente que envíe cualquiera de esos números en `X-Sync-Version` pasa.

### 5. Dejar `required_app_version` vacío

Aunque la política siga siendo `same_version` (default), si `SYNC_REQUIRED_APP_VERSION` está vacío y `APP_VERSION` también lo está, `isAppPolicyEnforced()` retorna `false` y nunca bloquea.

En [`config/sync.php`](../config/sync.php) el fallback es en cadena:
`SYNC_REQUIRED_APP_VERSION` → `SYNC_CURRENT_APP_VERSION` → `APP_VERSION`.
Si ninguna está definida, no enforza.

---

## Recomendación por entorno

| Entorno | Política | Variable adicional |
|---------|----------|---------------------|
| Desarrollo | `none` | — |
| Staging | `minimum_version` | `SYNC_MINIMUM_APP_VERSION=<baseline>` |
| Producción estable | `minimum_version` | `SYNC_MINIMUM_APP_VERSION=<baseline>` |
| Producción con rollout | `explicit_list` | `SYNC_COMPATIBLE_APP_VERSIONS=<lista>` |
| Lockdown extremo | `same_version` | `SYNC_REQUIRED_APP_VERSION=<exact>` (default actual) |

---

## Verificar la configuración activa

```bash
cd /mnt/wwn-0x5000c500e9568dfa-part2/Work/MiraloAki/ElVendedor/el-vendedor-back

php artisan tinker --execute="
  \$svc = app(App\Support\Sync\SyncCompatibility::class);
  echo 'policy: '.\$svc->appPolicy().PHP_EOL;
  echo 'enforced: '.json_encode(\$svc->isAppPolicyEnforced()).PHP_EOL;
  echo 'required_app_version: '.(\$svc->requiredAppVersion() ?? 'null').PHP_EOL;
  echo 'minimum_app_version: '.(\$svc->minimumAppVersion() ?? 'null').PHP_EOL;
  echo 'compatible_app_versions: '.json_encode(\$svc->compatibleAppVersions()).PHP_EOL;
  echo 'protocol_version: '.\$svc->currentProtocolVersion().PHP_EOL;
  echo 'supported_protocol_versions: '.json_encode(\$svc->supportedProtocolVersions()).PHP_EOL;
"
```

Después de cambiar el `.env`, limpiar caché de config:

```bash
php artisan config:clear
# o en producción:
php artisan config:cache
```

---

## Códigos de error que emite el servidor

Cuando la validación falla, el cliente recibe HTTP 409 con un `code` en el JSON. El frontend los mapea a mensajes localizados:

| Código | Causa | Retryable |
|--------|-------|-----------|
| `sync_protocol_version_missing` | Cliente no envía `X-Sync-Version` | No — requiere actualizar app |
| `sync_protocol_version_mismatch` | Protocolo no está en `supported_protocol_versions` | No — requiere actualizar app |
| `sync_client_app_version_missing` | Cliente no envía `X-Client-App-Version` ni `device.app_version` | No |
| `sync_app_version_mismatch` | Política `same_version` y la versión del cliente difiere de `required_app_version` | No |
| `sync_app_version_too_old` | Política `minimum_version` y cliente está por debajo del mínimo | No |
| `sync_app_version_not_allowed` | Política `explicit_list` y cliente no está en la lista | No |

Los errores de versión son `retryable: false` en el cliente — esto evita reintentos automáticos que saturarían el backend con requests condenados a fallar.
