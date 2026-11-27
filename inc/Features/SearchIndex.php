<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

use KoperasiKita\Api\Ajax;
use KoperasiKita\Images\FeaturedImage;
use WP_Post;
use WP_Query;

/**
 * Indexed fast search (`{prefix}kk_search_index`).
 *
 * One row per searchable post: the title, the stripped content and
 * excerpt, and the category and tag names, concatenated into one
 * searchable blob. A FULLTEXT index on (title, content) answers a
 * live query with MATCH ... AGAINST in boolean mode, so typing in
 * the header field returns matches from the index instead of the
 * LIKE scan WP_Query runs on wp_posts.
 *
 * Scope matches the plain WP search exactly: post, page and agenda
 * (all public post types with exclude_from_search false), published
 * only, never password-protected (indexing a protected post would
 * leak its content through the results). Nothing else is indexed.
 *
 * The table is created lazily on the first write (dbDelta, guarded
 * by a version option), mirroring Subscribers and GoldPricesTable:
 * the theme has no activation hook that would run on the
 * already-live site, so the first indexed post creates it. Reads
 * against a missing or empty table fall back to a plain WP_Query
 * search, so live search works before the backfill runs and the
 * feature never goes silent.
 *
 * Indexing happens on save_post (publish indexes, anything else
 * removes the row) and delete_post removes it. The one-time
 * backfill for existing posts is an admin action on the Setup page
 * (Setup::handleRebuild), safe to re-run: the upsert keys on the
 * post id, so a re-run only refreshes rows.
 *
 * The lookup is MATCH ... AGAINST in boolean mode, every term
 * required with a prefix wildcard, so typing "rapat ang" already
 * matches "Rapat Anggota". Terms shorter than three characters
 * cannot use the FULLTEXT index (the InnoDB minimum token size),
 * so they filter with LIKE instead; a query of only short terms
 * runs a pure LIKE search. When the FULLTEXT index is missing
 * (dbDelta skipped it) the MATCH query errors and the same terms
 * run as LIKE, so a missing index degrades instead of breaking.
 *
 * Columns: post_id, post_type, title, content (the searchable
 * blob), post_date, updated_at.
 */
final class SearchIndex
{
    public const VERSION = '1';

    public const VERSION_OPTION = 'kk_search_index_version';

    /** @var array<int, string> */
    public const POST_TYPES = ['post', 'page', 'agenda'];

    public const SEARCH_LIMIT = 8;

    public const MIN_QUERY_LENGTH = 2;

    public const MAX_TERMS = 5;

    public const BACKFILL_BATCH = 100;

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kk_search_index';
    }

    public static function register(): void
    {
        add_action('save_post', [self::class, 'onSavePost'], 10, 3);
        add_action('delete_post', [self::class, 'onDeletePost']);
        Ajax::add('kk_search', [self::class, 'ajaxSearch'], true);
    }

    public static function maybeCreate(): void
    {
        if (get_option(self::VERSION_OPTION) === self::VERSION) {
            return;
        }

        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            post_id bigint(20) unsigned NOT NULL,
            post_type varchar(20) NOT NULL,
            title text NOT NULL,
            content longtext NOT NULL,
            post_date datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (post_id),
            FULLTEXT KEY title_content (title, content)
        ) {$charset};";

        dbDelta($sql);
        update_option(self::VERSION_OPTION, self::VERSION, false);
    }

    public static function tableExists(): bool
    {
        static $exists = null;

        if ($exists !== null) {
            return $exists;
        }

        global $wpdb;

        $table = self::table();

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;

        return $exists;
    }

    public static function isBuilt(): bool
    {
        return get_option(self::VERSION_OPTION) === self::VERSION && self::tableExists();
    }

    public static function count(): int
    {
        if (!self::tableExists()) {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table());
    }

    /**
     * Index one post, or remove its row when it must not be
     * searchable. Returns false when the post is not indexed.
     */
    public static function indexPost(int $postId): bool
    {
        $post = get_post($postId);

        if (!$post instanceof WP_Post || !self::isIndexable($post)) {
            self::removePost($postId);

            return false;
        }

        self::maybeCreate();

        global $wpdb;

        $table = self::table();
        $now = current_time('mysql');

        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (post_id, post_type, title, content, post_date, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE post_type = VALUES(post_type), title = VALUES(title),
                 content = VALUES(content), post_date = VALUES(post_date), updated_at = VALUES(updated_at)",
                $post->ID,
                $post->post_type,
                $post->post_title,
                self::buildContent($post),
                $post->post_date,
                $now
            )
        );

        return $result !== false;
    }

    public static function removePost(int $postId): void
    {
        if (!self::tableExists()) {
            return;
        }

        global $wpdb;

        $wpdb->delete(self::table(), ['post_id' => $postId], ['%d']);
    }

    /**
     * Index every published searchable post, in batches. Safe to
     * re-run: the upsert keys on the post id.
     *
     * @return array{indexed: int, skipped: int, total: int}
     */
    public static function backfill(): array
    {
        $indexed = 0;
        $skipped = 0;
        $total = 0;
        $page = 1;

        do {
            $query = new WP_Query([
                'post_type' => self::POST_TYPES,
                'post_status' => 'publish',
                'has_password' => false,
                'posts_per_page' => self::BACKFILL_BATCH,
                'paged' => $page,
                'fields' => 'ids',
                'no_found_rows' => false,
            ]);

            $total = (int) $query->found_posts;

            foreach ($query->posts as $postId) {
                if (self::indexPost((int) $postId)) {
                    $indexed++;
                } else {
                    $skipped++;
                }
            }

            $page++;
        } while ($page <= $query->max_num_pages && $query->max_num_pages > 0);

        return [
            'indexed' => $indexed,
            'skipped' => $skipped,
            'total' => $total,
        ];
    }

    /**
     * Search the index for a query. Returns result rows shaped for
     * the live results panel: id, title, url, type, category (the
     * first category name, or null for pages and agenda), date and
     * thumbnail.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $query, int $limit = self::SEARCH_LIMIT): array
    {
        $query = trim($query);

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        [$long, $short] = self::splitTerms($query);

        if ($long === [] && $short === []) {
            return [];
        }

        if (!self::tableExists() || self::count() === 0) {
            return self::wpQuerySearch($query, $limit);
        }

        $rows = $long === [] ? null : self::matchRows($long, $short, $limit);

        if ($rows === null) {
            $rows = self::likeRows(array_merge($long, $short), $limit);
        }

        return self::mapRows($rows);
    }

    /**
     * The live endpoint (kk_search): a query string in, matching
     * posts out. A query under two characters is an empty result,
     * not an error - the field only sends longer queries.
     *
     * @return array{query: string, results: array<int, array<string, mixed>>, total: int}
     */
    public static function ajaxSearch(): array
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
        $raw = wp_unslash($_POST);

        $query = isset($raw['q']) && is_string($raw['q']) ? sanitize_text_field($raw['q']) : '';

        $results = self::search($query);

        return [
            'query' => $query,
            'results' => $results,
            'total' => count($results),
        ];
    }

    public static function onSavePost(int $postId, WP_Post $post, bool $update): void
    {
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }

        self::indexPost($postId);
    }

    public static function onDeletePost(int $postId): void
    {
        self::removePost($postId);
    }

    private static function isIndexable(WP_Post $post): bool
    {
        return in_array($post->post_type, self::POST_TYPES, true)
            && $post->post_status === 'publish'
            && $post->post_password === '';
    }

    /**
     * The searchable blob: title, excerpt, stripped content and the
     * category and tag names, one per line. Shortcodes and markup
     * are stripped - they are layout, not words to match.
     */
    private static function buildContent(WP_Post $post): string
    {
        $parts = [$post->post_title];

        $excerpt = get_the_excerpt($post);

        if (is_string($excerpt) && $excerpt !== '') {
            $parts[] = $excerpt;
        }

        $content = html_entity_decode(
            wp_strip_all_tags(strip_shortcodes($post->post_content)),
            ENT_QUOTES,
            'UTF-8'
        );

        if ($content !== '') {
            $parts[] = $content;
        }

        foreach (get_the_category($post->ID) as $category) {
            $parts[] = $category->name;
        }

        $tags = get_the_tags($post->ID);

        if (is_array($tags)) {
            foreach ($tags as $tag) {
                $parts[] = $tag->name;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Split a query into search terms: at most five, stripped of
     * anything that is not a letter or a digit (boolean operators
     * and punctuation must never reach MATCH). Terms of three or
     * more characters go to FULLTEXT; shorter ones to LIKE.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private static function splitTerms(string $query): array
    {
        $long = [];
        $short = [];

        $words = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($words)) {
            return [[], []];
        }

        foreach (array_slice($words, 0, self::MAX_TERMS) as $word) {
            $term = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $word);

            if ($term === '') {
                continue;
            }

            if (mb_strlen($term) >= 3) {
                $long[] = $term;
            } else {
                $short[] = $term;
            }
        }

        return [$long, $short];
    }

    /**
     * The FULLTEXT lookup. Returns null when the MATCH query
     * errors (the index is missing), so the caller falls back to
     * LIKE instead of failing.
     *
     * @param array<int, string> $long
     * @param array<int, string> $short
     * @return array<int, object>|null
     */
    private static function matchRows(array $long, array $short, int $limit): ?array
    {
        global $wpdb;

        $table = self::table();
        $boolean = implode(' ', array_map(static fn (string $term): string => '+' . $term . '*', $long));

        $where = "MATCH(title, content) AGAINST (%s IN BOOLEAN MODE)";
        $params = [$boolean];

        foreach ($short as $term) {
            $like = '%' . $wpdb->esc_like($term) . '%';
            $where .= ' AND (title LIKE %s OR content LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = $wpdb->prepare(
            "SELECT post_id, post_type, title, post_date,
             MATCH(title, content) AGAINST (%s IN BOOLEAN MODE) AS score
             FROM {$table} WHERE {$where}
             ORDER BY score DESC, post_date DESC LIMIT %d",
            ...array_merge([$boolean], $params, [$limit])
        );

        $rows = $wpdb->get_results($sql);

        if (!is_array($rows) || $wpdb->last_error !== '') {
            return null;
        }

        return $rows;
    }

    /**
     * The LIKE lookup: every term must appear in the title or the
     * blob. Used for queries of only short terms, and when the
     * FULLTEXT index is missing.
     *
     * @param array<int, string> $terms
     * @return array<int, object>
     */
    private static function likeRows(array $terms, int $limit): array
    {
        global $wpdb;

        $table = self::table();
        $where = [];
        $params = [];

        foreach ($terms as $term) {
            $like = '%' . $wpdb->esc_like($term) . '%';
            $where[] = '(title LIKE %s OR content LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = $wpdb->prepare(
            'SELECT post_id, post_type, title, post_date FROM ' . $table
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY post_date DESC LIMIT %d',
            ...array_merge($params, [$limit])
        );

        $rows = $wpdb->get_results($sql);

        return is_array($rows) ? $rows : [];
    }

    /**
     * The pre-backfill lookup: the plain WP search, so live search
     * works before the index is built.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function wpQuerySearch(string $query, int $limit): array
    {
        $posts = get_posts([
            's' => $query,
            'post_type' => self::POST_TYPES,
            'post_status' => 'publish',
            'has_password' => false,
            'numberposts' => $limit,
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        $rows = [];

        foreach ($posts as $post) {
            $rows[] = (object) [
                'post_id' => $post->ID,
                'post_type' => $post->post_type,
                'title' => get_the_title($post),
                'post_date' => $post->post_date,
            ];
        }

        return self::mapRows($rows);
    }

    /**
     * @param array<int, object> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function mapRows(array $rows): array
    {
        $results = [];

        foreach ($rows as $row) {
            $postId = (int) $row->post_id;
            $url = get_permalink($postId);

            if (!is_string($url) || $url === '') {
                continue;
            }

            $category = null;

            if ($row->post_type === 'post') {
                $categories = get_the_category($postId);

                if ($categories !== []) {
                    $category = $categories[0]->name;
                }
            }

            $results[] = [
                'id' => $postId,
                'title' => (string) $row->title,
                'url' => $url,
                'type' => (string) $row->post_type,
                'category' => $category,
                'date' => date_i18n((string) get_option('date_format', 'F j, Y'), strtotime((string) $row->post_date)),
                'thumbnail' => FeaturedImage::url($postId, 'medium'),
            ];
        }

        return $results;
    }
}
