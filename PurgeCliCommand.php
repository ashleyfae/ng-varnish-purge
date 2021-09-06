<?php
/**
 * PurgeCliCommand.php
 *
 * @package   ng-varnish-purge
 * @copyright Copyright (c) 2021, Ashley Gibson
 * @license   GPL2+
 */

namespace NgVarnishPurge;

class PurgeCliCommand extends \WP_CLI_Command
{
    /**
     * Purge a URL.
     *
     * ## OPTIONS
     * [<url>]
     * : URL to purge.
     *
     * [--wildcard]
     * : Include all subfolders and files.
     *
     * ## EXAMPLES
     *      wp varnish purge https://www.nosegraze.com
     *      wp varnish purge https://www.nosegraze.com --wildcard
     *
     * @param  array  $args
     * @param  array  $assoc_args
     */
    public function purge($args, $assoc_args)
    {
        if (! empty($args)) {
            list($url) = $args;
        }

        // This is a full purge if wildcard is set or the URL is empty.
        $regex = isset($assoc_args['wildcard']) || empty($url) ? NgVarnishPurge::WILDCARD : '';

        if (empty($url)) {
            $url = home_url();
        }

        if (isset($assoc_args['wildcard'])) {
            $url = untrailingslashit($url);
        }

        \WP_CLI::log(sprintf(
            'Purging URL %s with regex %s',
            $url,
            ($regex ? : __('(n/a)', 'ng-varnish-purge'))
        ));

        NgVarnishPurge::instance()->purgeUrl($url, $regex);

        \WP_CLI::success(__('Cache successfully purged.', 'ng-varnish-purge'));
    }
}
