<?php
/**
 * CDN Enabler engine
 *
 * @since  2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CDN_Enabler_Engine {

    /**
     * start engine
     *
     * @since   2.0.0
     * @change  2.0.0
     */

    public static function start() {

        new self();
    }


    /**
     * engine settings from database
     *
     * @since   2.0.0
     * @change  2.0.0
     *
     * @var     array
     */

    public static $settings;


    /**
     * constructor
     *
     * @since   2.0.0
     * @change  2.0.0
     */

    public function __construct() {

        // get settings from database
        self::$settings = CDN_Enabler::get_settings();

        if ( ! empty( self::$settings ) ) {
            self::start_buffering();
            // Run before WP REST Cache save (which uses priority 1000)
            add_filter( 'rest_pre_echo_response', [ self::class, 'rewrite_rest_pre_echo_response' ], 990, 3 );
        }
    }


    /**
     * start output buffering
     *
     * @since   2.0.0
     * @change  2.0.8
     */

    private static function start_buffering() {

        ob_start( self::class . '::end_buffering' );
    }


    /**
     * end output buffering and rewrite contents if applicable
     *
     * @since   2.0.0
     * @change  2.0.3
     *
     * @param   string   $contents                      contents from the output buffer
     * @param   integer  $phase                         bitmask of PHP_OUTPUT_HANDLER_* constants
     * @return  string   $contents|$rewritten_contents  rewritten contents from the output buffer if applicable, unchanged otherwise
     */

    private static function end_buffering( $contents, $phase ) {

        if ( $phase & PHP_OUTPUT_HANDLER_FINAL || $phase & PHP_OUTPUT_HANDLER_END ) {
            if ( ! self::bypass_rewrite() ) {
                $rewritten_contents = self::rewriter( $contents );

                return $rewritten_contents;
            }
        }

        return $contents;
    }


    /**
     * Sanitize server input string.
     *
     * @since   2.0.5
     * @change  2.0.5
     *
     * @param   string  $str Input string.
     * @param   bool    $strict Strictly sanitized.
     * @return  string  Sanitized input string.
     */
    public static function sanitize_server_input($str, $strict = true) {

        if ( is_object( $str ) || is_array( $str ) ) {
            return '';
        }

        $str = (string) $str;
        if ( 0 === strlen( $str ) ) {
            return '';
        }

        $filtered = preg_replace( '/[\r\n\t ]+/', ' ', $str );
        $filtered = trim( $filtered );

        if ( $strict ) {
            $found = false;
            while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
                $filtered = str_replace( $match[0], '', $filtered );
                $found    = true;
            }

            if ( $found ) {
                $filtered = trim( preg_replace( '/ +/', ' ', $filtered ) );
            }
        }

        return $filtered;
    }


    /**
     * check if file URL is excluded from rewrite
     *
     * @since   2.0.0
     * @change  2.0.0
     *
     * @param   string   $file_url  full or relative URL to potentially exclude from being rewritten
     * @return  boolean             true if file URL is excluded from the rewrite, false otherwise
     */

    private static function is_excluded( $file_url ) {

        // if string excluded (case sensitive)
        if ( ! empty( self::$settings['excluded_strings'] ) ) {
            $excluded_strings = explode( PHP_EOL, self::$settings['excluded_strings'] );

            foreach ( $excluded_strings as $excluded_string ) {
                if ( strpos( $file_url, $excluded_string ) !== false ) {
                    return true;
                }
            }
        }

        return false;
    }


    /**
     * check if administrative interface page
     *
     * @since   2.0.1
     * @change  2.0.1
     *
     * @return  boolean  true if administrative interface page, false otherwise
     */

    private static function is_admin() {

        if ( apply_filters( 'cdn_enabler_exclude_admin', is_admin() ) ) {
            return true;
        }

        return false;
    }


    /**
     * check if rewrite should be bypassed
     *
     * @since   2.0.0
     * @change  2.0.1
     *
     * @return  boolean  true if rewrite should be bypassed, false otherwise
     */

    private static function bypass_rewrite() {

        // bypass rewrite hook
        if ( apply_filters( 'cdn_enabler_bypass_rewrite', false ) ) {
            return true;
        }

        // check request method
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'GET' ) {
            return true;
        }

        // check conditional tags
        if ( self::is_admin() || is_trackback() || is_robots() || is_preview() ) {
            return true;
        }

        return false;
    }


    /**
     * rewrite URL to use CDN hostname
     *
     * @since   2.0.0
     * @change  2.0.2
     *
     * @param   array   $matches   pattern matches from parsed contents
     * @return  string  $file_url  rewritten file URL if applicable, unchanged otherwise
     */

    private static function rewrite_url( $matches ) {

        $file_url       = $matches[0];
        $site_hostname  = ( ! empty( $_SERVER['HTTP_HOST'] ) ) ? self::sanitize_server_input( $_SERVER['HTTP_HOST'] ) : parse_url( home_url(), PHP_URL_HOST );
        $site_hostnames = (array) apply_filters( 'cdn_enabler_site_hostnames', array( $site_hostname ) );
        $cdn_hostname   = self::$settings['cdn_hostname'];

        // if excluded or already using CDN hostname
        if ( self::is_excluded( $file_url ) || stripos( $file_url, $cdn_hostname ) !== false ) {
            return $file_url;
        }

        // rewrite full URL (e.g. https://www.example.com/wp..., https:\/\/www.example.com\/wp..., or //www.example.com/wp...)
        foreach ( $site_hostnames as $site_hostname ) {
            if ( stripos( $file_url, '//' . $site_hostname ) !== false || stripos( $file_url, '\/\/' . $site_hostname ) !== false ) {
                return substr_replace( $file_url, $cdn_hostname, stripos( $file_url, $site_hostname ), strlen( $site_hostname ) );
            }
        }

        // rewrite relative URLs hook
        if ( apply_filters( 'cdn_enabler_rewrite_relative_urls', true ) ) {
            // rewrite relative URL (e.g. /wp-content/uploads/example.jpg)
            if ( strpos( $file_url, '//' ) !== 0 && strpos( $file_url, '/' ) === 0 ) {
                return '//' . $cdn_hostname . $file_url;
            }

            // rewrite escaped relative URL (e.g. \/wp-content\/uploads\/example.jpg)
            if ( strpos( $file_url, '\/\/' ) !== 0 && strpos( $file_url, '\/' ) === 0 ) {
                return '\/\/' . $cdn_hostname . $file_url;
            }
        }

        return $file_url;
    }


    /**
     * rewrite contents
     *
     * @since   2.0.0
     * @change  2.0.8
     *
     * @param   string  $contents                      contents to parse
     * @return  string  $contents|$rewritten_contents  rewritten contents if applicable, unchanged otherwise
     */

    public static function rewriter( $contents ) {

        // check rewrite requirements
        if ( ! is_string( $contents ) || empty( self::$settings['cdn_hostname'] ) || empty( self::$settings['included_file_extensions'] ) ) {
            return $contents;
        }

        $contents = apply_filters( 'cdn_enabler_contents_before_rewrite', $contents );

        $included_file_extensions_regex = quotemeta( implode( '|', explode( PHP_EOL, self::$settings['included_file_extensions'] ) ) );

        $urls_regex = '#(?:(?:[\"\'\s=>,;]|url\()\K|^)[^\"\'\s(=>,;]+(' . $included_file_extensions_regex . ')(\?[^\/?\\\"\'\s)>,]+)?(?:(?=\/?[?\\\"\'\s)>,&])|$)#i';

        $rewritten_contents = apply_filters( 'cdn_enabler_contents_after_rewrite', preg_replace_callback( $urls_regex, self::class . '::rewrite_url', $contents ) );

        return $rewritten_contents;
    }

    /**
     * Check if rewrite should be bypassed for REST responses.
     *
     * Similar to bypass_rewrite(), but uses the WP_REST_Request method
     * instead of relying on $_SERVER['REQUEST_METHOD'].
     *
     * @since   2.0.9
     *
     * @param   WP_REST_Request $request
     * @return  bool  true if rewrite should be bypassed, false otherwise
     */
    private static function bypass_rewrite_rest( $request ) {

        // bypass rewrite hook
        if ( apply_filters( 'cdn_enabler_bypass_rewrite', false ) ) {
            return true;
        }

        // check request method (REST-aware)
        $method = method_exists( $request, 'get_method' ) ? strtoupper( $request->get_method() ) : '';
        if ( empty( $method ) || ! in_array( $method, [ 'GET', 'HEAD' ], true ) ) {
            return true;
        }

        // check conditional tags
        if ( self::is_admin() || is_trackback() || is_robots() || is_preview() ) {
            return true;
        }

        return false;
    }

    public static function rewrite_rest_pre_echo_response( $result, $server, $request ) {
        if ( self::bypass_rewrite_rest( $request ) ) {
            return $result;
        }

        // Don't touch errors
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // If WP_REST_Response, rewrite its data payload
        if ( $result instanceof WP_REST_Response ) {
            $data = $result->get_data();
            $rewritten = self::rewrite_rest_payload_data( $data );
            if ( $rewritten !== $data ) {
                $result->set_data( $rewritten );
            }
            return $result;
        }

        // Otherwise treat it as raw data
        return self::rewrite_rest_payload_data( $result );
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    private static function rewrite_rest_payload_data( $data ) {
        if ( ! is_array( $data ) && ! is_object( $data ) ) {
            return $data;
        }

        $json = wp_json_encode( $data );
        if ( false === $json || null === $json ) {
            return $data;
        }

        $rewritten_json = self::rewriter( $json );
        if ( $rewritten_json === $json ) {
            return $data;
        }

        $decoded = json_decode( $rewritten_json, true );
        if ( JSON_ERROR_NONE !== json_last_error() || null === $decoded ) {
            return $data;
        }

        return $decoded;
    }
}
