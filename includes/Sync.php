<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/M3uParser.php';

/**
 * Lógica central de sincronización de un proveedor.
 *
 * Identidad del canal: se usa tvg-id cuando el proveedor lo entrega (no
 * vacío); si no, se usa la URL de transmisión. Esto permite detectar como
 * "modificado" un canal que cambió de nombre, categoría, logo o incluso de
 * URL (cuando hay tvg-id estable), en vez de reportarlo como eliminado +
 * agregado. Si el proveedor nunca entrega tvg-id, un cambio de URL para ese
 * canal seguirá viéndose como baja + alta (limitación documentada: sin un
 * tvg-id estable no hay forma fiable de saber que es "el mismo" canal).
 */
class Sync
{
    private PDO $pdo;

    // Eventos (mismo formato que channel_changes) generados durante la
    // corrida en curso de syncProvider() — se resetea al inicio de cada
    // llamada y se devuelve junto con el resultado, para que el llamador
    // pueda notificar por Telegram (ver includes/Telegram.php) sin tener
    // que releer channel_changes de la base de datos.
    private array $pendingEvents = [];

    // user_id dueño del proveedor que se está sincronizando en este momento
    // — se fija al inicio de syncProvider() y lo usan getOrCreateCategory(),
    // logChange() y startRun() para no tener que pasarlo por cada método.
    private int $userId = 0;

    public function __construct()
    {
        $this->pdo = get_pdo();
    }

    public function syncProvider(int $providerId): array
    {
        $pdo = $this->pdo;

        $stmt = $pdo->prepare("SELECT * FROM providers WHERE id = ?");
        $stmt->execute([$providerId]);
        $provider = $stmt->fetch();
        if (!$provider) {
            throw new RuntimeException("Proveedor #$providerId no encontrado");
        }

        // Si nunca se sincronizó antes, esta corrida marcará CADA canal
        // como "added" (no hay nada previo con qué comparar) — no debe
        // disparar notificaciones de Telegram. Se captura ANTES de que el
        // UPDATE de más abajo actualice last_checked_at.
        $wasFirstSync = $provider['last_checked_at'] === null;
        $this->pendingEvents = [];
        $this->userId = (int)$provider['user_id'];

        $runId = $this->startRun($providerId);

        try {
            $content = $this->fetchM3u($provider['m3u_url']);
            $parsed = M3uParser::parse($content);

            $existing = $this->loadActiveChannels($providerId);
            $seenHashes = [];

            $added = 0;
            $removed = 0;
            $modified = 0;
            // Se pide la hora a MySQL, no a PHP: si el timezone de PHP no
            // coincide con el de MySQL (ej. XAMPP trae "Europe/Berlin" fijo
            // en php.ini sin importar dónde esté el servidor real), un
            // timestamp calculado en PHP y comparado después contra el NOW()
            // de MySQL (como hace el cron al elegir qué proveedor le toca)
            // queda desfasado exactamente por esa diferencia horaria.
            $now = $pdo->query('SELECT NOW()')->fetchColumn();

            foreach ($parsed as $entry) {
                try {
                    $url = trim($entry['url'] ?? '');
                    if ($url === '') {
                        continue;
                    }
                    $tvgId = $entry['tvg_id'] !== null ? trim($entry['tvg_id']) : null;
                    $logo = $entry['logo'] !== null ? trim($entry['logo']) : null;
                    $name = $entry['name'];
                    $categoryId = $this->getOrCreateCategory($entry['group'] ?? null, $providerId);

                    $identityKey = ($tvgId !== null && $tvgId !== '') ? ('tvgid:' . $tvgId) : ('url:' . $url);
                    $hash = hash('sha256', $identityKey);

                    if (isset($seenHashes[$hash])) {
                        // Misma identidad repetida dentro de la misma lista: se ignora la repetición
                        continue;
                    }
                    $seenHashes[$hash] = true;

                    if (isset($existing[$hash])) {
                        $chan = $existing[$hash];
                        $changedFields = [];

                        if ($chan['name'] !== $name) {
                            $changedFields[] = 'nombre';
                        }
                        if ((int)$chan['category_id'] !== (int)$categoryId) {
                            $changedFields[] = 'categoría';
                        }
                        if ((string)$chan['stream_url'] !== $url) {
                            $changedFields[] = 'URL';
                        }
                        // logo_manual=1: el logo se asignó a mano (ver api/logo_search.php)
                        // — no se compara ni se pisa con lo que traiga el M3U, para no
                        // deshacer la asignación manual en cada sincronización.
                        $logoManual = !empty($chan['logo_manual']);
                        if (!$logoManual && (string)($chan['logo_url'] ?? '') !== (string)($logo ?? '')) {
                            $changedFields[] = 'logo';
                        }
                        if ((string)($chan['tvg_id'] ?? '') !== (string)($tvgId ?? '')) {
                            $changedFields[] = 'tvg-id';
                        }

                        if (!empty($changedFields)) {
                            $this->logChange(
                                $providerId,
                                (int)$chan['id'],
                                'modified',
                                $chan['name'],
                                $name,
                                (int)$chan['category_id'],
                                $categoryId,
                                'Se actualizó: ' . implode(', ', $changedFields)
                            );
                            $modified++;
                        }

                        $finalLogo = $logoManual ? $chan['logo_url'] : $logo;
                        $stmt = $pdo->prepare("UPDATE channels SET name=?, tvg_id=?, logo_url=?, stream_url=?, category_id=?, last_seen_at=?, status='active' WHERE id=?");
                        $stmt->execute([$name, $tvgId, $finalLogo, $url, $categoryId, $now, $chan['id']]);
                    } else {
                        // Puede que este canal ya exista en la BD pero marcado 'removed'
                        // (el proveedor lo había quitado y ahora reapareció con la misma
                        // identidad). En ese caso hay que reactivarlo con UPDATE: un INSERT
                        // chocaría con la restricción única (provider_id, identity_hash) y
                        // reventaría el resto de la sincronización.
                        $stmt = $pdo->prepare("SELECT id, logo_url, logo_manual FROM channels WHERE provider_id = ? AND identity_hash = ? LIMIT 1");
                        $stmt->execute([$providerId, $hash]);
                        $reappeared = $stmt->fetch();

                        if ($reappeared) {
                            $channelId = (int)$reappeared['id'];
                            $finalLogo = !empty($reappeared['logo_manual']) ? $reappeared['logo_url'] : $logo;
                            $stmt = $pdo->prepare("UPDATE channels SET name=?, tvg_id=?, logo_url=?, stream_url=?, category_id=?, status='active', last_seen_at=? WHERE id=?");
                            $stmt->execute([$name, $tvgId, $finalLogo, $url, $categoryId, $now, $channelId]);
                            $this->logChange($providerId, $channelId, 'added', null, $name, null, $categoryId, 'El canal reapareció tras haber sido marcado como eliminado');
                            $added++;
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO channels (user_id, provider_id, category_id, name, tvg_id, logo_url, stream_url, identity_hash, status, first_seen_at, last_seen_at) VALUES (?,?,?,?,?,?,?,?,'active',?,?)");
                            $stmt->execute([$this->userId, $providerId, $categoryId, $name, $tvgId, $logo, $url, $hash, $now, $now]);
                            $newId = (int)$pdo->lastInsertId();
                            $this->logChange($providerId, $newId, 'added', null, $name, null, $categoryId, null);
                            $added++;
                        }
                    }
                } catch (Throwable $entryError) {
                    // Un error puntual en un canal no debe abortar el resto de la sincronización
                    error_log('[iptv-watch] Error procesando canal en sync de proveedor #' . $providerId . ': ' . $entryError->getMessage());
                    continue;
                }
            }

            // Lo que quedó en $existing sin marcarse como visto ya no está en la lista => eliminado
            foreach ($existing as $hash => $chan) {
                if (!isset($seenHashes[$hash])) {
                    $stmt = $pdo->prepare("UPDATE channels SET status='removed' WHERE id=?");
                    $stmt->execute([$chan['id']]);
                    $this->logChange($providerId, (int)$chan['id'], 'removed', $chan['name'], null, (int)$chan['category_id'], null, 'No apareció en la última verificación');
                    $removed++;
                }
            }

            $interval = max(5, (int)$provider['check_interval_minutes']);
            $stmt = $pdo->prepare("UPDATE providers SET last_checked_at=?, next_check_at=DATE_ADD(NOW(), INTERVAL ? MINUTE), last_sync_status='ok', last_error=NULL, consecutive_failures=0 WHERE id=?");
            $stmt->execute([$now, $interval, $providerId]);

            $this->finishRun($runId, 'ok', $added, $removed, $modified, null);

            return [
                'added' => $added,
                'removed' => $removed,
                'modified' => $modified,
                'was_first_sync' => $wasFirstSync,
                'events' => $this->pendingEvents,
                'provider_name' => $provider['name'],
                'user_id' => $this->userId,
            ];
        } catch (Throwable $e) {
            $failures = (int)$provider['consecutive_failures'] + 1;
            // Backoff progresivo con techo: 5, 10, 20, 40, 80, 160... hasta un
            // máximo de 240 min (4h). Un proveedor recién roto reintenta
            // pronto; uno que lleva días caído deja de insistir cada 5
            // minutos contra algo que no responde.
            $delayMinutes = min(240, 5 * (2 ** ($failures - 1)));
            $stmt = $pdo->prepare("UPDATE providers SET last_checked_at=NOW(), next_check_at=DATE_ADD(NOW(), INTERVAL ? MINUTE), last_sync_status='error', last_error=?, consecutive_failures=? WHERE id=?");
            $stmt->execute([$delayMinutes, $e->getMessage(), $failures, $providerId]);
            $this->finishRun($runId, 'error', 0, 0, 0, $e->getMessage());
            throw $e;
        }
    }

    private function fetchM3u(string $url): string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 25,
                'header' => "User-Agent: IPTV-Watch/1.0\r\n",
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $content = @file_get_contents($url, false, $ctx);
        if ($content === false || trim($content) === '') {
            throw new RuntimeException("No se pudo descargar o la lista M3U está vacía: $url");
        }
        return $content;
    }

    private function loadActiveChannels(int $providerId): array
    {
        $stmt = $this->pdo->prepare("SELECT id, name, category_id, identity_hash, stream_url, logo_url, tvg_id, logo_manual FROM channels WHERE provider_id = ? AND status = 'active'");
        $stmt->execute([$providerId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['identity_hash']] = $row;
        }
        return $map;
    }

    private function getOrCreateCategory(?string $name, int $providerId): int
    {
        $name = trim((string)$name);
        if ($name === '') {
            $name = 'Sin clasificar';
        }
        $stmt = $this->pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = ?");
        $stmt->execute([$this->userId, $name]);
        $row = $stmt->fetch();
        if ($row) {
            return (int)$row['id'];
        }

        $stmt = $this->pdo->prepare("INSERT INTO categories (user_id, name) VALUES (?, ?)");
        $stmt->execute([$this->userId, $name]);
        $newId = (int)$this->pdo->lastInsertId();

        // Se registra una única vez, la primera vez que esta categoría aparece.
        // Como el INSERT solo ocurre la primera vez (búsqueda por nombre arriba),
        // esta categoría nunca podrá volver a marcarse como "nueva" después.
        $this->logChange($providerId, null, 'category_added', null, null, null, $newId, 'Categoría nueva detectada en la lista');
        // "new_name" se deja NULL en channel_changes a propósito (el dashboard
        // resuelve el nombre de la categoría por su id, ver renderChanges());
        // aquí se completa solo en el evento en memoria, para que el mensaje
        // de Telegram pueda mostrar el nombre de la categoría nueva.
        $this->pendingEvents[count($this->pendingEvents) - 1]['new_name'] = $name;

        return $newId;
    }

    private function logChange(int $providerId, ?int $channelId, string $type, ?string $oldName, ?string $newName, ?int $oldCat, ?int $newCat, ?string $detail): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO channel_changes (user_id, provider_id, channel_id, type, old_name, new_name, old_category_id, new_category_id, detail) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$this->userId, $providerId, $channelId, $type, $oldName, $newName, $oldCat, $newCat, $detail]);

        $this->pendingEvents[] = [
            'type' => $type,
            'old_name' => $oldName,
            'new_name' => $newName,
            'detail' => $detail,
            // Categoría vigente del canal en este momento (o la propia
            // categoría nueva, si type=category_added) — Telegram.php la usa
            // para mostrar "Categoría: X" igual que el panel.
            'category_id' => $newCat ?? $oldCat,
        ];
    }

    private function startRun(int $providerId): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO sync_runs (user_id, provider_id, started_at, status) VALUES (?, ?, NOW(), 'running')");
        $stmt->execute([$this->userId, $providerId]);
        return (int)$this->pdo->lastInsertId();
    }

    private function finishRun(int $runId, string $status, int $added, int $removed, int $modified, ?string $error): void
    {
        $stmt = $this->pdo->prepare("UPDATE sync_runs SET finished_at=NOW(), status=?, channels_added=?, channels_removed=?, channels_modified=?, error_message=? WHERE id=?");
        $stmt->execute([$status, $added, $removed, $modified, $error, $runId]);
    }
}
