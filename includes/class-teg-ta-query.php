<?php
/**
 * Contains the query functions for TEGTwitterAPI which alter the front-end post queries and loops
 *
 * @class        TEG_TA_Query
 * @version        1.0.0
 * @package        TEG_Twitter_API/Classes
 * @category    Class
 * @author        ThemeEgg
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * TEG_TA_Query Class.
 */
class TEG_TA_Query
{

    /** @public array Query vars to add to wp */
    public $query_vars = array();

    /**
     * Stores chosen attributes
     * @var array
     */
    private static $_chosen_attributes;

    /**
     * Constructor for the query class. Hooks in methods.
     *
     * @access public
     */
    public function __construct()
    {
        add_action('init', array($this, 'add_endpoints'));
        if (!is_admin()) {
            add_action('wp_loaded', array($this, 'get_errors'), 20);
            add_filter('query_vars', array($this, 'add_query_vars'), 0);
            add_action('parse_request', array($this, 'parse_request'), 0);
            add_action('pre_get_posts', array($this, 'pre_get_posts'));
            add_action('wp', array($this, 'remove_product_query'));
            add_action('wp', array($this, 'remove_ordering_args'));
        }
        $this->init_query_vars();
    }

    /**
     * Get any errors from querystring.
     */
    public function get_errors()
    {
        if (!empty($_GET['teg_ta_error']) && ($error = sanitize_text_field($_GET['teg_ta_error'])) && !teg_ta_has_notice($error, 'error')) {
            teg_ta_add_notice($error, 'error');
        }
    }

    /**
     * Init query vars by loading options.
     */
    public function init_query_vars()
    {
        // Query vars to add to WP.
        $this->query_vars = array(
            // Checkout actions.
            'test_index' => get_option('teg_twitter_api_checkout_pay_endpoint', 'test_index'),


        );
    }

    /**
     * Get page title for an endpoint.
     * @param  string
     * @return string
     */
    public function get_endpoint_title($endpoint)
    {
        global $wp;

        switch ($endpoint) {
            case 'test_index' :
                $title = __('Pay for order', 'teg-twitter-api');
                break;
            default :
                $title = '';
                break;
        }

        return apply_filters('teg_twitter_api_endpoint_' . $endpoint . '_title', $title, $endpoint);
    }

    /**
     * Endpoint mask describing the places the endpoint should be added.
     *
     * @since 2.6.2
     * @return int
     */
    public function get_endpoints_mask()
    {
        if ('page' === get_option('show_on_front')) {
            $page_on_front = get_option('page_on_front');
            $myaccount_page_id = get_option('teg_twitter_api_myaccount_page_id');
            $checkout_page_id = get_option('teg_twitter_api_checkout_page_id');

            if (in_array($page_on_front, array($myaccount_page_id, $checkout_page_id))) {
                return EP_ROOT | EP_PAGES;
            }
        }

        return EP_PAGES;
    }

    /**
     * Add endpoints for query vars.
     */
    public function add_endpoints()
    {
        $mask = $this->get_endpoints_mask();

        foreach ($this->query_vars as $key => $var) {
            if (!empty($var)) {
                add_rewrite_endpoint($var, $mask);
            }
        }
    }

    /**
     * Add query vars.
     *
     * @access public
     * @param array $vars
     * @return array
     */
    public function add_query_vars($vars)
    {
        foreach ($this->get_query_vars() as $key => $var) {
            $vars[] = $key;
        }
        return $vars;
    }

    /**
     * Get query vars.
     *
     * @return array
     */
    public function get_query_vars()
    {
        return apply_filters('teg_twitter_api_get_query_vars', $this->query_vars);
    }

    /**
     * Get query current active query var.
     *
     * @return string
     */
    public function get_current_endpoint()
    {
        global $wp;
        foreach ($this->get_query_vars() as $key => $value) {
            if (isset($wp->query_vars[$key])) {
                return $key;
            }
        }
        return '';
    }

    /**
     * Parse the request and look for query vars - endpoints may not be supported.
     */
    public function parse_request()
    {
        global $wp;

        // Map query vars to their keys, or get them if endpoints are not supported
        foreach ($this->get_query_vars() as $key => $var) {
            if (isset($_GET[$var])) {
                $wp->query_vars[$key] = $_GET[$var];
            } elseif (isset($wp->query_vars[$var])) {
                $wp->query_vars[$key] = $wp->query_vars[$var];
            }
        }
    }

    /**
     * Are we currently on the front page?
     *
     * @param object $q
     *
     * @return bool
     */
    private function is_showing_page_on_front($q)
    {
        return $q->is_home() && 'page' === get_option('show_on_front');
    }

    /**
     * Is the front page a page we define?
     *
     * @param int $page_id
     *
     * @return bool
     */
    private function page_on_front_is($page_id)
    {
        return absint(get_option('page_on_front')) === absint($page_id);
    }

    /**
     * Hook into pre_get_posts to do the main product query.
     *
     * @param object $q query object
     */
    public function pre_get_posts($q)
    {
        // We only want to affect the main query
        if (!$q->is_main_query()) {
            return;
        }

        // Fix for endpoints on the homepage
        if ($this->is_showing_page_on_front($q) && !$this->page_on_front_is($q->get('page_id'))) {
            $_query = wp_parse_args($q->query);
            if (!empty($_query) && array_intersect(array_keys($_query), array_keys($this->query_vars))) {
                $q->is_page = true;
                $q->is_home = false;
                $q->is_singular = true;
                $q->set('page_id', (int)get_option('page_on_front'));
                add_filter('redirect_canonical', '__return_false');
            }
        }

        // When orderby is set, WordPress shows posts. Get around that here.
        if ($this->is_showing_page_on_front($q) && $this->page_on_front_is(teg_ta_get_page_id('shop'))) {
            $_query = wp_parse_args($q->query);
            if (empty($_query) || !array_diff(array_keys($_query), array('preview', 'page', 'paged', 'cpage', 'orderby'))) {
                $q->is_page = true;
                $q->is_home = false;
                $q->set('page_id', (int)get_option('page_on_front'));
                $q->set('post_type', 'product');
            }
        }

        // Fix product feeds
        if ($q->is_feed() && $q->is_post_type_archive('product')) {
            $q->is_comment_feed = false;
        }

        // Special check for shops with the product archive on front
        if ($q->is_page() && 'page' === get_option('show_on_front') && absint($q->get('page_id')) === teg_ta_get_page_id('shop')) {
            // This is a front-page shop
            $q->set('post_type', 'product');
            $q->set('page_id', '');

            if (isset($q->query['paged'])) {
                $q->set('paged', $q->query['paged']);
            }

            // Define a variable so we know this is the front page shop later on
            if (!defined('SHOP_IS_ON_FRONT')) {
                define('SHOP_IS_ON_FRONT', true);
            }

            // Get the actual WP page to avoid errors and let us use is_front_page()
            // This is hacky but works. Awaiting https://core.trac.wordpress.org/ticket/21096
            global $wp_post_types;

            $shop_page = get_post(teg_ta_get_page_id('shop'));

            $wp_post_types['product']->ID = $shop_page->ID;
            $wp_post_types['product']->post_title = $shop_page->post_title;
            $wp_post_types['product']->post_name = $shop_page->post_name;
            $wp_post_types['product']->post_type = $shop_page->post_type;
            $wp_post_types['product']->ancestors = get_ancestors($shop_page->ID, $shop_page->post_type);

            // Fix conditional Functions like is_front_page
            $q->is_singular = false;
            $q->is_post_type_archive = true;
            $q->is_archive = true;
            $q->is_page = true;

            // Remove post type archive name from front page title tag
            add_filter('post_type_archive_title', '__return_empty_string', 5);

            // Fix WP SEO
            if (class_exists('WPSEO_Meta')) {
                add_filter('wpseo_metadesc', array($this, 'wpseo_metadesc'));
                add_filter('wpseo_metakey', array($this, 'wpseo_metakey'));
            }

            // Only apply to product categories, the product post archive, the shop page, product tags, and product attribute taxonomies
        } elseif (!$q->is_post_type_archive('product') && !$q->is_tax(get_object_taxonomies('product'))) {
            return;
        }

        $this->product_query($q);

        if (is_search()) {
            add_filter('posts_where', array($this, 'search_post_excerpt'));
            add_filter('wp', array($this, 'remove_posts_where'));
        }

        // And remove the pre_get_posts hook
        $this->remove_product_query();
    }

    /**
     * Search post excerpt.
     *
     * @access public
     * @param string $where (default: '')
     * @return string (modified where clause)
     */
    public function search_post_excerpt($where = '')
    {
        global $wp_the_query;

        // If this is not a TEGTApi() Query, do not modify the query
        if (empty($wp_the_query->query_vars['teg_ta_query']) || empty($wp_the_query->query_vars['s'])) {
            return $where;
        }

        $where = preg_replace(
            "/post_title\s+LIKE\s*(\'\%[^\%]+\%\')/",
            "post_title LIKE $1) OR (post_excerpt LIKE $1", $where);

        return $where;
    }

    /**
     * WP SEO meta description.
     *
     * Hooked into wpseo_ hook already, so no need for function_exist.
     *
     * @access public
     * @return string
     */
    public function wpseo_metadesc()
    {
        return WPSEO_Meta::get_value('metadesc', teg_ta_get_page_id('shop'));
    }

    /**
     * WP SEO meta key.
     *
     * Hooked into wpseo_ hook already, so no need for function_exist.
     *
     * @access public
     * @return string
     */
    public function wpseo_metakey()
    {
        return WPSEO_Meta::get_value('metakey', teg_ta_get_page_id('shop'));
    }

    /**
     * Query the products, applying sorting/ordering etc. This applies to the main wordpress loop.
     *
     * @param mixed $q
     */
    public function product_query($q)
    {
        // Ordering query vars
        if (!$q->is_search()) {
            $ordering = $this->get_catalog_ordering_args();
            $q->set('orderby', $ordering['orderby']);
            $q->set('order', $ordering['order']);
            if (isset($ordering['meta_key'])) {
                $q->set('meta_key', $ordering['meta_key']);
            }
        } else {
            $q->set('orderby', 'relevance');
        }

        // Query vars that affect posts shown
        $q->set('meta_query', $this->get_meta_query($q->get('meta_query'), true));
        $q->set('tax_query', $this->get_tax_query($q->get('tax_query'), true));
        $q->set('posts_per_page', $q->get('posts_per_page') ? $q->get('posts_per_page') : apply_filters('loop_shop_per_page', get_option('posts_per_page')));
        $q->set('teg_ta_query', 'product_query');
        $q->set('post__in', array_unique((array)apply_filters('loop_shop_post_in', array())));

        do_action('teg_twitter_api_product_query', $q, $this);
    }


    /**
     * Remove the query.
     */
    public function remove_product_query()
    {
        remove_action('pre_get_posts', array($this, 'pre_get_posts'));
    }

    /**
     * Remove ordering queries.
     */
    public function remove_ordering_args()
    {
        remove_filter('posts_clauses', array($this, 'order_by_price_asc_post_clauses'));
        remove_filter('posts_clauses', array($this, 'order_by_price_desc_post_clauses'));
        remove_filter('posts_clauses', array($this, 'order_by_popularity_post_clauses'));
        remove_filter('posts_clauses', array($this, 'order_by_rating_post_clauses'));
    }

    /**
     * Remove the posts_where filter.
     */
    public function remove_posts_where()
    {
        remove_filter('posts_where', array($this, 'search_post_excerpt'));
    }

    /**
     * Returns an array of arguments for ordering products based on the selected values.
     *
     * @access public
     *
     * @param string $orderby
     * @param string $order
     *
     * @return array
     */
    public function get_catalog_ordering_args($orderby = '', $order = '')
    {
        // Get ordering from query string unless defined
        if (!$orderby) {
            $orderby_value = isset($_GET['orderby']) ? teg_ta_clean($_GET['orderby']) : apply_filters('teg_twitter_api_default_catalog_orderby', get_option('teg_twitter_api_default_catalog_orderby'));

            // Get order + orderby args from string
            $orderby_value = explode('-', $orderby_value);
            $orderby = esc_attr($orderby_value[0]);
            $order = !empty($orderby_value[1]) ? $orderby_value[1] : $order;
        }

        $orderby = strtolower($orderby);
        $order = strtoupper($order);
        $args = array();

        // default - menu_order
        $args['orderby'] = 'menu_order title';
        $args['order'] = ('DESC' === $order) ? 'DESC' : 'ASC';
        $args['meta_key'] = '';

        switch ($orderby) {
            case 'rand' :
                $args['orderby'] = 'rand';
                break;
            case 'date' :
                $args['orderby'] = 'date ID';
                $args['order'] = ('ASC' === $order) ? 'ASC' : 'DESC';
                break;
            case 'price' :
                if ('DESC' === $order) {
                    add_filter('posts_clauses', array($this, 'order_by_price_desc_post_clauses'));
                } else {
                    add_filter('posts_clauses', array($this, 'order_by_price_asc_post_clauses'));
                }
                break;
            case 'popularity' :
                $args['meta_key'] = 'total_sales';

                // Sorting handled later though a hook
                add_filter('posts_clauses', array($this, 'order_by_popularity_post_clauses'));
                break;
            case 'rating' :
                $args['meta_key'] = '_teg_ta_average_rating';
                $args['orderby'] = array(
                    'meta_value_num' => 'DESC',
                    'ID' => 'ASC',
                );
                break;
            case 'title' :
                $args['orderby'] = 'title';
                $args['order'] = ('DESC' === $order) ? 'DESC' : 'ASC';
                break;
        }

        return apply_filters('teg_twitter_api_get_catalog_ordering_args', $args);
    }

    /**
     * Handle numeric price sorting.
     *
     * @access public
     * @param array $args
     * @return array
     */
    public function order_by_price_asc_post_clauses($args)
    {
        global $wpdb;
        $args['join'] .= " INNER JOIN ( SELECT post_id, min( meta_value+0 ) price FROM $wpdb->postmeta WHERE meta_key='_price' GROUP BY post_id ) as price_query ON $wpdb->posts.ID = price_query.post_id ";
        $args['orderby'] = " price_query.price ASC ";
        return $args;
    }

    /**
     * Handle numeric price sorting.
     *
     * @access public
     * @param array $args
     * @return array
     */
    public function order_by_price_desc_post_clauses($args)
    {
        global $wpdb;
        $args['join'] .= " INNER JOIN ( SELECT post_id, max( meta_value+0 ) price FROM $wpdb->postmeta WHERE meta_key='_price' GROUP BY post_id ) as price_query ON $wpdb->posts.ID = price_query.post_id ";
        $args['orderby'] = " price_query.price DESC ";
        return $args;
    }

    /**
     * WP Core doens't let us change the sort direction for invidual orderby params - https://core.trac.wordpress.org/ticket/17065.
     *
     * This lets us sort by meta value desc, and have a second orderby param.
     *
     * @access public
     * @param array $args
     * @return array
     */
    public function order_by_popularity_post_clauses($args)
    {
        global $wpdb;
        $args['orderby'] = "$wpdb->postmeta.meta_value+0 DESC, $wpdb->posts.post_date DESC";
        return $args;
    }

    /**
     * Order by rating post clauses.
     *
     * @deprecated 3.0.0
     * @param array $args
     * @return array
     */
    public function order_by_rating_post_clauses($args)
    {
        global $wpdb;

        teg_ta_deprecated_function('order_by_rating_post_clauses', '3.0');

        $args['fields'] .= ", AVG( $wpdb->commentmeta.meta_value ) as average_rating ";
        $args['where'] .= " AND ( $wpdb->commentmeta.meta_key = 'rating' OR $wpdb->commentmeta.meta_key IS null ) ";
        $args['join'] .= "
			LEFT OUTER JOIN $wpdb->comments ON($wpdb->posts.ID = $wpdb->comments.comment_post_ID)
			LEFT JOIN $wpdb->commentmeta ON($wpdb->comments.comment_ID = $wpdb->commentmeta.comment_id)
		";
        $args['orderby'] = "average_rating DESC, $wpdb->posts.post_date DESC";
        $args['groupby'] = "$wpdb->posts.ID";

        return $args;
    }

    /**
     * Appends meta queries to an array.
     *
     * @param  array $meta_query
     * @param  bool $main_query
     * @return array
     */
    public function get_meta_query($meta_query = array(), $main_query = false)
    {
        if (!is_array($meta_query)) {
            $meta_query = array();
        }
        $meta_query['price_filter'] = $this->price_filter_meta_query();
        return array_filter(apply_filters('teg_twitter_api_product_query_meta_query', $meta_query, $this));
    }

    /**
     * Appends tax queries to an array.
     * @param array $tax_query
     * @param bool $main_query
     * @return array
     */
    public function get_tax_query($tax_query = array(), $main_query = false)
    {
        if (!is_array($tax_query)) {
            $tax_query = array('relation' => 'AND');
        }

        // Layered nav filters on terms.
        if ($main_query && ($_chosen_attributes = $this->get_layered_nav_chosen_attributes())) {
            foreach ($_chosen_attributes as $taxonomy => $data) {
                $tax_query[] = array(
                    'taxonomy' => $taxonomy,
                    'field' => 'slug',
                    'terms' => $data['terms'],
                    'operator' => 'and' === $data['query_type'] ? 'AND' : 'IN',
                    'include_children' => false,
                );
            }
        }

        $product_visibility_terms = teg_ta_get_product_visibility_term_ids();
        $product_visibility_not_in = array(is_search() && $main_query ? $product_visibility_terms['exclude-from-search'] : $product_visibility_terms['exclude-from-catalog']);

        // Hide out of stock products.
        if ('yes' === get_option('teg_twitter_api_hide_out_of_stock_items')) {
            $product_visibility_not_in[] = $product_visibility_terms['outofstock'];
        }

        // Filter by rating.
        if (isset($_GET['rating_filter'])) {
            $rating_filter = array_filter(array_map('absint', explode(',', $_GET['rating_filter'])));
            $rating_terms = array();
            for ($i = 1; $i <= 5; $i++) {
                if (in_array($i, $rating_filter) && isset($product_visibility_terms['rated-' . $i])) {
                    $rating_terms[] = $product_visibility_terms['rated-' . $i];
                }
            }
            if (!empty($rating_terms)) {
                $tax_query[] = array(
                    'taxonomy' => 'product_visibility',
                    'field' => 'term_taxonomy_id',
                    'terms' => $rating_terms,
                    'operator' => 'IN',
                    'rating_filter' => true,
                );
            }
        }

        if (!empty($product_visibility_not_in)) {
            $tax_query[] = array(
                'taxonomy' => 'product_visibility',
                'field' => 'term_taxonomy_id',
                'terms' => $product_visibility_not_in,
                'operator' => 'NOT IN',
            );
        }

        return array_filter(apply_filters('teg_twitter_api_product_query_tax_query', $tax_query, $this));
    }

    /**
     * Return a meta query for filtering by price.
     * @return array
     */
    private function price_filter_meta_query()
    {
        if (isset($_GET['max_price']) || isset($_GET['min_price'])) {
            $meta_query = teg_ta_get_min_max_price_meta_query($_GET);
            $meta_query['price_filter'] = true;

            return $meta_query;
        }

        return array();
    }

    /**
     * Return a meta query for filtering by rating.
     *
     * @deprecated 3.0.0 Replaced with taxonomy.
     * @return array
     */
    public function rating_filter_meta_query()
    {
        return array();
    }

    /**
     * Returns a meta query to handle product visibility.
     *
     * @deprecated 3.0.0 Replaced with taxonomy.
     * @param string $compare (default: 'IN')
     * @return array
     */
    public function visibility_meta_query($compare = 'IN')
    {
        return array();
    }

    /**
     * Returns a meta query to handle product stock status.
     *
     * @deprecated 3.0.0 Replaced with taxonomy.
     * @param string $status (default: 'instock')
     * @return array
     */
    public function stock_status_meta_query($status = 'instock')
    {
        return array();
    }

    /**
     * Get the tax query which was used by the main query.
     * @return array
     */
    public static function get_main_tax_query()
    {
        global $wp_the_query;

        $tax_query = isset($wp_the_query->tax_query, $wp_the_query->tax_query->queries) ? $wp_the_query->tax_query->queries : array();

        return $tax_query;
    }

    /**
     * Get the meta query which was used by the main query.
     * @return array
     */
    public static function get_main_meta_query()
    {
        global $wp_the_query;

        $args = $wp_the_query->query_vars;
        $meta_query = isset($args['meta_query']) ? $args['meta_query'] : array();

        return $meta_query;
    }

    /**
     * Based on WP_Query::parse_search
     */
    public static function get_main_search_query_sql()
    {
        global $wp_the_query, $wpdb;

        $args = $wp_the_query->query_vars;
        $search_terms = isset($args['search_terms']) ? $args['search_terms'] : array();
        $sql = array();

        foreach ($search_terms as $term) {
            // Terms prefixed with '-' should be excluded.
            $include = '-' !== substr($term, 0, 1);

            if ($include) {
                $like_op = 'LIKE';
                $andor_op = 'OR';
            } else {
                $like_op = 'NOT LIKE';
                $andor_op = 'AND';
                $term = substr($term, 1);
            }

            $like = '%' . $wpdb->esc_like($term) . '%';
            $sql[] = $wpdb->prepare("(($wpdb->posts.post_title $like_op %s) $andor_op ($wpdb->posts.post_excerpt $like_op %s) $andor_op ($wpdb->posts.post_content $like_op %s))", $like, $like, $like);
        }

        if (!empty($sql) && !is_user_logged_in()) {
            $sql[] = "($wpdb->posts.post_password = '')";
        }

        return implode(' AND ', $sql);
    }

    /**
     * Layered Nav Init.
     */
    public static function get_layered_nav_chosen_attributes()
    {
        if (!is_array(self::$_chosen_attributes)) {
            self::$_chosen_attributes = array();

            if ($attribute_taxonomies = teg_ta_get_attribute_taxonomies()) {
                foreach ($attribute_taxonomies as $tax) {
                    $attribute = teg_ta_sanitize_taxonomy_name($tax->attribute_name);
                    $taxonomy = teg_ta_attribute_taxonomy_name($attribute);
                    $filter_terms = !empty($_GET['filter_' . $attribute]) ? explode(',', teg_ta_clean($_GET['filter_' . $attribute])) : array();

                    if (empty($filter_terms) || !taxonomy_exists($taxonomy)) {
                        continue;
                    }

                    $query_type = !empty($_GET['query_type_' . $attribute]) && in_array($_GET['query_type_' . $attribute], array('and', 'or')) ? teg_ta_clean($_GET['query_type_' . $attribute]) : '';
                    self::$_chosen_attributes[$taxonomy]['terms'] = array_map('sanitize_title', $filter_terms); // Ensures correct encoding
                    self::$_chosen_attributes[$taxonomy]['query_type'] = $query_type ? $query_type : apply_filters('teg_twitter_api_layered_nav_default_query_type', 'and');
                }
            }
        }
        return self::$_chosen_attributes;
    }

    /**
     * @deprecated 2.6.0
     */
    public function layered_nav_init()
    {
        teg_ta_deprecated_function('layered_nav_init', '2.6');
    }

    /**
     * Get an unpaginated list all product IDs (both filtered and unfiltered). Makes use of transients.
     * @deprecated 2.6.0 due to performance concerns
     */
    public function get_products_in_view()
    {
        teg_ta_deprecated_function('get_products_in_view', '2.6');
    }

    /**
     * Layered Nav post filter.
     * @deprecated 2.6.0 due to performance concerns
     *
     * @param $filtered_posts
     */
    public function layered_nav_query($filtered_posts)
    {
        teg_ta_deprecated_function('layered_nav_query', '2.6');
    }
}