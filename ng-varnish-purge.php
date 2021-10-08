<?php
/**
 * Plugin Name: NG Varnish Purge
 * Description: Sends HTTP BAN requests to URLs oc ahnged posts/pages when they are modified.
 * Version: 1.0
 * Author: Ashley Gibson
 * Author URI: https://www.nosegraze.com
 * Text Domain: ng-varnish-purge
 *
 * @package   ng-varnish-purge
 * @copyright Copyright (c) 2021, Ashley Gibson
 * @license   GPL2+
 */

namespace NgVarnishPurge;

class NgVarnishPurge
{
    const WILDCARD = '.*';

    private static ?NgVarnishPurge $instance = null;

    private array $registeredEvents = [
        'save_post',
        'deleted_post',
        'trashed_post',
        'edit_post',
        'delete_attachment',
        'switch_theme',
        'comment_post',
    ];

    private array $urlsToPurge = [];

    private $varnishHost;

    public static function instance(): self
    {
        if (is_null(self::$instance)) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function boot(): void
    {
        load_plugin_textdomain('ng-varnish-purge');

        $this->maybeLoadCliCommand();

        $this->varnishHost = defined('VHP_VARNISH_IP') ? VHP_VARNISH_IP : null;

        add_action('admin_notices', [$this, 'displayAdminNotices']);
        add_action('admin_bar_menu', [$this, 'adminBarNode'], 100);
        add_action('shutdown', [$this, 'executePurge']);
        add_action('transition_post_status', [$this, 'purgeFeed'], 10, 3);

        foreach ($this->registeredEvents as $event) {
            add_action($event, [$this, 'purgePost'], 10, 2);
        }
    }

    private function maybeLoadCliCommand(): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            require_once dirname(__FILE__).'/PurgeCliCommand.php';

            \WP_CLI::add_command('varnish', PurgeCliCommand::class);
        }
    }

    public function displayAdminNotices(): void
    {
        if (isset($_GET['nvp_flush_all']) && check_admin_referer('purge_varnish_cache')) {
            ?>
            <div class="notice updated fade">
                <p><?php esc_html_e('Varnish cache purged.', 'ng-varnish-purge'); ?></p>
            </div>
            <?php
        }
    }

    public function adminBarNode(\WP_Admin_Bar $adminBar): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $adminBar->add_menu([
            'id'    => 'ng-varnish-purge',
            'title' => __('Purge Varnish', 'ng-varnish-purge'),
            'href'  => wp_nonce_url(add_query_arg('nvp_flush_all', 1), 'purge_varnish_cache'),
            'meta'  => [
                'title' => __('Purge Varnish', 'ng-varnish-purge'),
            ]
        ]);
    }

    public function executePurge(): void
    {
        if (isset($_GET['post'])) {
            $this->purgePost(intval($_GET['post']));
        }

        $purgeUrls = array_unique($this->urlsToPurge);

        if (! empty($purgeUrls)) {
            // Purge specific URLs.
            foreach ($purgeUrls as $url) {
                $this->purgeUrl($url);
            }
        } elseif (isset($_GET['nvp_flush_all']) && current_user_can('manage_options') && check_admin_referer('purge_varnish_cache')) {
            // Purge the entire site.
            $this->purgeUrl(home_url(), self::WILDCARD);
        }
    }

    public function purgeUrl(string $url, string $regex = ''): void
    {
        $urlPieces = parse_url($url);
        $host      = $this->varnishHost ? : $urlPieces['host'];
        $purgeUrl  = 'http://'.$host.($urlPieces['path'] ?? '').$regex;

        wp_remote_request(
            $purgeUrl,
            [
                'method'  => 'BAN',
                'headers' => [
                    [
                        'host' => $urlPieces['host']
                    ]
                ]
            ]
        );
    }

    public function purgePost(int $postId): void
    {
        $postPermalink = get_permalink($postId);

        // Post isn't public, so no need to purge.
        if ($postPermalink !== true && ! in_array(get_post_status($postId), ['publish', 'trash'])) {
            return;
        }

        $this->urlsToPurge[] = $postPermalink;

        // Purge category archives.
        foreach (get_the_category($postId) as $category) {
            $this->urlsToPurge[] = get_category_link($category->term_id);
        }

        // Purge tag archives.
        $tags = get_the_tags($postId);
        if (is_array($tags)) {
            foreach ($tags as $tag) {
                $this->urlsToPurge[] = get_tag_link($tag->term_id);
            }
        }

        // Purge this author's archives.
        array_push(
            $this->urlsToPurge,
            get_author_posts_url(get_post_field('post_author', $postId)),
            get_author_feed_link(get_post_field('post_author', $postId))
        );

        // Archives and their feeds.
        if (get_post_type_archive_link(get_post_type($postId)) === true) {
            array_push(
                $this->urlsToPurge,
                get_post_type_archive_link(get_post_type($postId)),
                get_post_type_archive_feed_link(get_post_type($postId))
            );
        }

        // Feeds
        array_push(
            $this->urlsToPurge,
            get_bloginfo_rss('rdf_url'),
            get_bloginfo_rss('rss_url'),
            trailingslashit(get_bloginfo_rss('rss_url')),
            get_bloginfo_rss('rss2_url'),
            trailingslashit(get_bloginfo_rss('rss2_url')),
            get_bloginfo_rss('atom_url'),
            get_bloginfo_rss('comments_rss2_url'),
            get_post_comments_feed_link($postId)
        );

        // Homepage and posts page, if used.
        $this->urlsToPurge[] = trailingslashit(home_url());
        if (get_option('show_on_front') === 'page') {
            $this->urlsToPurge[] = get_permalink(get_option('page_for_posts'));
        }

        // Purge all pages.
        $pages = get_pages();
        if (is_array($pages)) {
            foreach ($pages as $page) {
                $this->urlsToPurge[] = get_permalink($page);
            }
        }

        /**
         * Actual purge happens on shutdown.
         *
         * @see NgVarnishPurge::executePurge()
         */
    }

    public function purgeFeed(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if ($oldStatus !== 'publish' && $newStatus !== 'publish') {
            $this->purgeUrl(home_url('/feed/'), self::WILDCARD);
        }
    }

}

NgVarnishPurge::instance()->boot();
