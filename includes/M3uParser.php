<?php

/**
 * Parser simple de listas M3U / M3U Plus.
 * Extrae, por cada canal: nombre, tvg-id, group-title y URL de transmisión.
 */
class M3uParser
{
    public static function parse(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $channels = [];
        $current = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (stripos($line, '#EXTINF') === 0) {
                $current = self::parseExtinf($line);
            } elseif ($line[0] === '#') {
                // otras directivas (#EXTM3U, #EXTGRP, #EXTVLCOPT, etc.) — se ignoran
                continue;
            } else {
                // línea de URL de transmisión
                if ($current !== null) {
                    $current['url'] = $line;
                    $channels[] = $current;
                    $current = null;
                }
            }
        }

        return $channels;
    }

    private static function parseExtinf(string $line): array
    {
        $attrs = [];
        if (preg_match_all('/([A-Za-z0-9_-]+)="([^"]*)"/', $line, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $attrs[strtolower($m[1])] = $m[2];
            }
        }

        $commaPos = strrpos($line, ',');
        $name = $commaPos !== false ? trim(substr($line, $commaPos + 1)) : '';
        if ($name === '') {
            $name = $attrs['tvg-name'] ?? 'Canal sin nombre';
        }

        return [
            'name'   => $name,
            'tvg_id' => $attrs['tvg-id'] ?? null,
            'group'  => $attrs['group-title'] ?? null,
            'logo'   => $attrs['tvg-logo'] ?? null,
        ];
    }
}
