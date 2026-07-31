<?php
/**
 * Adivina una categoría a partir del NOMBRE del canal, para listas M3U cuyo
 * group-title viene vacío o inútil (ej. "-"). Usado por api/m3u_upload.php.
 *
 * Coincidencia por palabra completa (no substring) para evitar falsos
 * positivos como "id" matcheando dentro de "Kids". Se compara contra el
 * nombre normalizado (minúsculas, sin tildes).
 */
class AutoCategorizer
{
    private const RULES = [
        'Deportes' => [
            'espn', 'fox sports', 'tnt sports', 'win sports', 'gol tv', 'gol caracol',
            'tyc sports', 'directv sports', 'bein sports', 'dazn', 'golf channel',
            'espn premium', 'espn extra', 'fox deportes', 'claro sports', 'star plus sports',
            'via x esports',
        ],
        'Películas' => [
            'hbo', 'cinemax', 'golden', 'space', 'tcm', 'claro cinema', 'de pelicula',
            'sony movies', 'cinecanal', 'studio universal', 'star movies', 'multipremier',
            'paramount channel', 'fx movies', 'cine', 'europa europa',
        ],
        'Series y Novelas' => [
            'tnt series', 'tnt novelas', 'sony', 'fx', 'amc series', 'amc',
            'universal channel', 'distrito comedia', 'comedy central', 'las estrellas',
            'telemundo', 'rcn novelas', 'nuestra tele', 'tlnovelas', 'atres series',
            'pasiones', 'caracol', 'warner', 'axn',
        ],
        'Documentales' => [
            'discovery', 'history', 'animal planet', 'national geographic', 'nat geo',
            'investigation discovery', 'film&arts', 'film & arts', 'el gourmet',
            'home and health',
        ],
        'Infantil' => [
            'disney', 'nick', 'cartoonito', 'teen nick', 'cartoon network', 'nick jr',
            'nickelodeon',
        ],
        'Noticias' => [
            'cnn', 'france 24', 'ntn24', 'cgtn', 'cctv', 'canal institucional',
            'canal del congreso', 'tv camara', 'rai italia', 'ecuador tv', '24 horas',
            'bbc news', 'telesur', 'c9n', 'movistar informa',
        ],
        'Música' => [
            'mtv', 'nick music', 'htv',
        ],
        'Adultos' => [
            'playboy', 'venus', 'private', 'brazzers',
        ],
        'Regionales Colombia' => [
            'teleantioquia', 'telecafe', 'telecaribe', 'telepacifico', 'teleislas',
            'senal colombia',
        ],
        'Canales Nacionales' => [
            'ecuavisa', 'gamatv', 'teleamazonas', 'rts', 'rtu', 'canal uno', 'city tv',
            'atv', 'telefe', 'canal 13', 'chv', 'mega', 'tvn', 'willax', 'tv peru',
            'trece', 'america tv',
        ],
    ];

    /** Devuelve el nombre de categoría detectado, o null si no hubo coincidencia. */
    public static function guess(string $channelName): ?string
    {
        $normalized = self::normalize($channelName);
        foreach (self::RULES as $category => $keywords) {
            foreach ($keywords as $keyword) {
                $pattern = '/\b' . preg_quote($keyword, '/') . '\b/u';
                if (preg_match($pattern, $normalized) === 1) {
                    return $category;
                }
            }
        }
        return null;
    }

    private static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
        ]);
        return $s;
    }
}
