<?php
/**
 * Plugin Name: Unified RSS Curator Venice Edition
 * Description: Aggregates RSS feeds and rewrites them using the Venice.ai Uncensored API.
 * Version: 1.0.0
 * Text Domain: unified-rss-curator
 */

declare(strict_types=1);

namespace UnifiedCurator;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define Constants
const URF_VERSION    = '1.0.0';

define( 'URF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'URF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Class Unified_RSS_Curator
 */
final class Unified_RSS_Curator {

	/**
	 * The single instance of the class.
	 */
	private static ?self $instance = null;

	/**
	 * Cache key base for combined feed data.
	 */
	private const CACHE_KEY = 'urf_combined_feed_data';

	/**
	 * Default system prompt.
	 */
	private const DEFAULT_PROMPT = "Rewrite the following RSS feed item into a unique, SEO-friendly blog post. Use HTML headers (h2, h3) and paragraph tags. Do not be preachy. Just rewrite the content.";

	/**
	 * Venice.ai API Endpoint (OpenAI Compatible).
	 */
	private const API_ENDPOINT = 'https://api.venice.ai/api/v1/chat/completions';

	/**
	 * The Uncensored Model ID.
	 */
	private const MODEL_ID = 'venice-uncensored';

	/**
	 * Admin page hooks.
	 */
	private string $dashboard_page_hook = '';
	private string $settings_page_hook = '';

	/**
	 * Instance Accessor.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers all hooks and filters.
	 */
	private function __construct() {
		// Admin Hooks
		add_action( 'init', [ $this, 'register_cpt' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post', [ $this, 'save_meta_boxes' ] );
		add_action( 'admin_menu', [ $this, 'add_admin_menus' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

		// AJAX Hooks - Note: Updated action names
		add_action( 'wp_ajax_urf_verify_venice', [ $this, 'ajax_verify_api_key' ] );
		add_action( 'wp_ajax_urf_rewrite_publish', [ $this, 'ajax_rewrite_publish' ] );

		// Public Hooks
		add_shortcode( 'unified_feed', [ $this, 'render_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_public_assets' ] );
	}

	/**
	 * Register Custom Post Type for Feed Sources.
	 */
	public function register_cpt(): void {
		register_post_type( 'urf_feed_source', [
			'labels' => [
				'name'          => 'Feeds',
				'singular_name' => 'Feed',
				'menu_name'     => 'Feeds',
				'all_items'     => 'All Feeds',
				'add_new'       => 'Add Feed',
				'add_new_item'  => 'Add New Feed',
				'edit_item'     => 'Edit Feed',
			],
			'public'      => false,
			'show_ui'     => true,
			'supports'    => [ 'title' ],
			'menu_icon'   => 'dashicons-rss',
		] );
	}

	/**
	 * Add Meta Box.
	 */
	public function add_meta_boxes(): void {
		add_meta_box(
			'urf_url_box',
			'RSS Feed URL',
			function( \WP_Post $post ) {
				wp_nonce_field( 'urf_save_meta', 'urf_meta_nonce' );
				$val = (string) get_post_meta( $post->ID, '_urf_feed_url', true );
				echo '<label class="screen-reader-text" for="urf_feed_url">RSS Feed URL</label>';
				echo '<input type="url" id="urf_feed_url" name="urf_feed_url" value="' . esc_attr( $val ) . '" class="widefat" placeholder="https://example.com/feed" required>';
				echo '<p class="description">Enter the valid XML RSS feed URL here.</p>';
			},
			'urf_feed_source',
			'normal',
			'high'
		);
	}

	/**
	 * Save Meta Box.
	 */
	public function save_meta_boxes( int $post_id ): void {
		if ( ! isset( $_POST['urf_meta_nonce'] ) || ! wp_verify_nonce( (string) $_POST['urf_meta_nonce'], 'urf_save_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		if ( isset( $_POST['urf_feed_url'] ) ) {
			$new_url = esc_url_raw( (string) $_POST['urf_feed_url'] );
			update_post_meta( $post_id, '_urf_feed_url', $new_url );

			// Clear Caches
			delete_transient( self::CACHE_KEY );
			delete_transient( self::CACHE_KEY . '_' . $post_id );
		}
	}

	/**
	 * Add Admin Menus.
	 */
	public function add_admin_menus(): void {
		$this->dashboard_page_hook = (string) add_submenu_page(
			'edit.php?post_type=urf_feed_source',
			'AI Dashboard',
			'AI Dashboard',
			'edit_posts',
			'urf_dashboard',
			[ $this, 'render_dashboard_page' ]
		);

		$this->settings_page_hook = (string) add_submenu_page(
			'edit.php?post_type=urf_feed_source',
			'Venice Settings',
			'Settings',
			'manage_options',
			'urf_settings',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Register Settings API.
	 */
	public function register_settings(): void {
		register_setting( 'urf_settings_group', 'urf_venice_api_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'urf_settings_group', 'urf_ai_prompt', [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
	}

	/**
	 * Enqueue Admin Assets.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( $hook === $this->settings_page_hook ) {
			wp_enqueue_script( 'urf-settings-js', URF_PLUGIN_URL . 'assets/js/admin-settings.js', [ 'jquery' ], URF_VERSION, true );
			wp_localize_script( 'urf-settings-js', 'urf_settings', [
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'urf_settings_nonce' ),
				'default_prompt' => self::DEFAULT_PROMPT
			] );
		}

		if ( $hook === $this->dashboard_page_hook ) {
			wp_enqueue_script( 'urf-dashboard-js', URF_PLUGIN_URL . 'assets/js/admin-dashboard.js', [ 'jquery' ], URF_VERSION, true );
			wp_localize_script( 'urf-dashboard-js', 'urf_dash', [
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'urf_dashboard_nonce' )
			] );
		}
	}

	/**
	 * Render Settings Page.
	 */
	public function render_settings_page(): void {
		$api_key = (string) get_option( 'urf_venice_api_key', '' );
		$prompt  = (string) get_option( 'urf_ai_prompt', self::DEFAULT_PROMPT );
		?>
		<style>
			.urf-modal-overlay { position: fixed; inset: 0; background-color: rgba(0, 0, 0, 0.5); backdrop-filter: blur(4px); z-index: 100000; display: none; align-items: center; justify-content: center; }
			.urf-modal-container { background-color: #fff; border-radius: 0.5rem; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); width: 100%; max-width: 28rem; padding: 1.5rem; transform: scale(0.95); transition: all 0.2s ease-in-out; opacity: 0; }
			.urf-modal-active .urf-modal-overlay { display: flex; }
			.urf-modal-active .urf-modal-container { transform: scale(1); opacity: 1; }
			.urf-modal-title { font-size: 1.25rem; font-weight: 600; color: #1f2937; margin-top:0; }
			.urf-modal-actions { display: flex; justify-content: flex-end; gap: 0.75rem; }
			.urf-btn { padding: 0.5rem 1rem; border-radius: 0.375rem; font-weight: 500; cursor: pointer; border: none; }
			.urf-btn-cancel { background-color: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
			.urf-btn-confirm { background-color: #2271b1; color: #ffffff; }
		</style>

		<div class="wrap">
			<h1>Venice.ai Uncensored Settings</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'urf_settings_group' ); ?>
				<?php do_settings_sections( 'urf_settings_group' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="urf_venice_api_key">Venice API Key</label></th>
						<td>
							<input type="password" id="urf_venice_api_key" name="urf_venice_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text">
							<button type="button" id="urf-verify-btn" class="button button-secondary">Test Connection</button>
							<span id="urf-verify-msg" style="margin-left:10px; font-weight:bold;"></span>
							<p class="description">
								Obtain your API Key from <a href="https://venice.ai/dashboard" target="_blank" rel="noopener">Venice.ai Dashboard</a>.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="urf_ai_prompt">System Prompt</label></th>
						<td>
							<textarea id="urf_ai_prompt" name="urf_ai_prompt" rows="6" class="large-text code"><?php echo esc_textarea( $prompt ); ?></textarea>
							<p class="description">Instruction for the <strong><?php echo self::MODEL_ID; ?></strong> model.</p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<?php submit_button( 'Save Settings', 'primary', 'submit', false ); ?>
					<span style="margin: 0 5px;"></span>
					<button type="button" id="urf-trigger-modal" class="button button-secondary">Restore Default Prompt</button>
				</p>
			</form>
		</div>

		<div id="urf-restore-modal" class="urf-modal-overlay">
			<div class="urf-modal-container">
				<h3 class="urf-modal-title">Restore Default Prompt?</h3>
				<p>Are you sure? Custom changes will be lost.</p>
				<div class="urf-modal-actions">
					<button type="button" id="urf-modal-cancel" class="urf-btn urf-btn-cancel">Cancel</button>
					<button type="button" id="urf-modal-confirm" class="urf-btn urf-btn-confirm">Yes, Restore</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Dashboard Page.
	 */
	public function render_dashboard_page(): void {
		$filter_id = isset( $_GET['source_id'] ) ? absint( $_GET['source_id'] ) : null;
		$items     = $this->fetch_feed_items( 50, $filter_id );
		$sources   = get_posts( [ 'post_type' => 'urf_feed_source', 'numberposts' => -1 ] );
		?>
		<style>
			.urf-action-wrapper { display: flex; flex-direction: column; gap: 8px; width: 100%; }
			.urf-action-wrapper .button { width: 100%; text-align: center; justify-content:center; display:flex; align-items:center; gap:5px; }
			.urf-action-wrapper .spinner { float: none; margin: 0 auto; }
		</style>
		<div class="wrap">
			<h1 class="wp-heading-inline">AI Curator Dashboard</h1>
			<p class="description">Curate content using the Uncensored Venice Model.</p>
			<hr class="wp-header-end">

			<form method="get" style="margin-bottom: 20px; background: #fff; padding: 15px; border: 1px solid #ccd0d4;">
				<input type="hidden" name="page" value="urf_dashboard">
				<input type="hidden" name="post_type" value="urf_feed_source">
				<div class="alignleft actions">
					<select name="source_id">
						<option value="">All Feeds</option>
						<?php foreach( $sources as $src ): ?>
							<option value="<?php echo esc_attr( (string) $src->ID ); ?>" <?php selected( $filter_id, $src->ID ); ?>><?php echo esc_html( $src->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button button-secondary">Filter</button>
				</div>
				<br class="clear">
			</form>

			<div id="urf-global-notice-area"></div>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th class="manage-column column-primary">Title / Content</th>
						<th class="manage-column">Source</th>
						<th class="manage-column">Date</th>
						<th class="manage-column" style="width: 200px;">Action</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $items ) ): ?>
					<tr><td colspan="4">No items found.</td></tr>
				<?php else: ?>
					<?php foreach ( $items as $item ): 
						$post_id = $this->get_imported_post_id( (string) $item['permalink'] );
					?>
						<tr class="<?php echo $post_id ? 'urf-imported-row' : ''; ?>">
							<td class="column-primary">
								<strong><a href="<?php echo esc_url( (string) $item['permalink'] ); ?>" target="_blank"><?php echo esc_html( (string) $item['title'] ); ?></a></strong>
								<button type="button" class="toggle-row"><span class="screen-reader-text">Show details</span></button>
							</td>
							<td><?php echo esc_html( (string) $item['source'] ); ?></td>
							<td><?php echo esc_html( date_i18n( 'M j, Y', (int) $item['date'] ) ); ?></td>
							<td>
								<div class="urf-action-wrapper">
									<?php if ( $post_id ): ?>
										<a href="<?php echo get_edit_post_link( $post_id ); ?>" class="button button-secondary" target="_blank"><span class="dashicons dashicons-edit"></span> Edit Draft</a>
										<button type="button" class="button button-small urf-rewrite-btn" data-title="<?php echo esc_attr( (string) $item['title'] ); ?>" data-link="<?php echo esc_attr( (string) $item['permalink'] ); ?>" data-source="<?php echo esc_attr( (string) $item['source'] ); ?>" data-content="<?php echo base64_encode( (string) $item['content'] ); ?>"><span class="dashicons dashicons-update"></span> Rewrite Again</button>
									<?php else: ?>
										<button type="button" class="button button-primary urf-rewrite-btn" data-title="<?php echo esc_attr( (string) $item['title'] ); ?>" data-link="<?php echo esc_attr( (string) $item['permalink'] ); ?>" data-source="<?php echo esc_attr( (string) $item['source'] ); ?>" data-content="<?php echo base64_encode( (string) $item['content'] ); ?>"><span class="dashicons dashicons-superhero-alt"></span> Rewrite</button>
									<?php endif; ?>
									<span class="spinner"></span>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Fetch and combine RSS items with Caching.
	 */
	private function fetch_feed_items( int $limit = 50, ?int $source_id = null ): array {
		$cache_key = self::CACHE_KEY . ( $source_id ? '_' . $source_id : '' );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) return array_slice( $cached, 0, $limit );

		$args = [ 'post_type' => 'urf_feed_source', 'posts_per_page' => -1, 'post_status' => 'publish' ];
		if ( $source_id ) $args['p'] = $source_id;
		$sources = get_posts( $args );

		if ( empty( $sources ) ) return [];

		include_once( ABSPATH . WPINC . '/feed.php' );
		$all_items = [];

		foreach ( $sources as $source ) {
			$url = get_post_meta( $source->ID, '_urf_feed_url', true );
			if ( ! $url || ! is_string( $url ) ) continue;

			$feed = fetch_feed( $url );
			if ( ! is_wp_error( $feed ) ) {
				$items = $feed->get_items( 0, 10 );
				foreach ( $items as $item ) {
					$content = $item->get_content() ? $item->get_content() : $item->get_description();
					$all_items[] = [
						'title'     => $item->get_title(),
						'permalink' => $item->get_permalink(),
						'date'      => $item->get_date( 'U' ),
						'content'   => $content,
						'source'    => $item->get_feed()->get_title(),
					];
				}
			}
		}

		usort( $all_items, fn($a, $b) => (int) $b['date'] <=> (int) $a['date'] );
		set_transient( $cache_key, $all_items, 900 );

		return array_slice( $all_items, 0, $limit );
	}

	/**
	 * Check imported post ID.
	 */
	private function get_imported_post_id( string $url ): int|false {
		global $wpdb;
		$query = $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s ORDER BY post_id DESC LIMIT 1", '_urf_original_link', $url );
		$post_id = $wpdb->get_var( $query );
		return $post_id ? (int) $post_id : false;
	}

	/**
	 * Verify API Key
	 */
	public function ajax_verify_api_key(): void {
		check_ajax_referer( 'urf_settings_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied.' );

		$key = isset( $_POST['api_key'] ) ? sanitize_text_field( (string) $_POST['api_key'] ) : '';

		// Send a simple ping to check auth
		$res = $this->call_venice_api( 'Ping', $key, 'Reply only with Pong' ); 

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( $res->get_error_message() );
		}
		wp_send_json_success( 'Verified' );
	}

	/**
	 * Rewrite & Publish
	 */
	public function ajax_rewrite_publish(): void {
		check_ajax_referer( 'urf_dashboard_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Permission denied.' );

		$title   = isset( $_POST['title'] ) ? sanitize_text_field( (string) $_POST['title'] ) : '';
		$link    = isset( $_POST['link'] ) ? esc_url_raw( (string) $_POST['link'] ) : '';
		$content = isset( $_POST['content'] ) ? wp_kses_post( (string) base64_decode( (string) $_POST['content'] ) ) : '';

		// Get Key from DB
		$api_key = (string) get_option( 'urf_venice_api_key', '' );
		if ( empty( $api_key ) ) wp_send_json_error( 'Venice API Key missing.' );

		$sys_prompt = (string) get_option( 'urf_ai_prompt', self::DEFAULT_PROMPT );

		// Call Venice
		$ai_text = $this->call_venice_api( $content, $api_key, $sys_prompt );

		if ( is_wp_error( $ai_text ) ) {
			wp_send_json_error( $ai_text->get_error_message() );
		}

		// Insert Draft
		$post_id = wp_insert_post( [
			'post_title'   => $title,
			'post_content' => $ai_text,
			'post_status'  => 'draft',
			'post_author'  => get_current_user_id(),
			'post_type'    => 'post'
		] );

		if ( is_wp_error( $post_id ) ) wp_send_json_error( 'DB Insert failed.' );

		update_post_meta( $post_id, '_urf_original_link', $link );
		wp_send_json_success( [ 'edit_url' => get_edit_post_link( $post_id, 'raw' ) ] );
	}

	/**
	 * Call Venice.ai API (OpenAI Compatible)
	 * Using native WP_Remote_Post
	 */
	private function call_venice_api( string $user_content, string $key, string $system_prompt ): string|\WP_Error {

		// Construct OpenAI-compatible Message Array
		$messages = [
			[
				'role'    => 'system',
				'content' => $system_prompt
			],
			[
				'role'    => 'user',
				'content' => $user_content
			]
		];

		// Prepare Payload
		$payload = [
			'model'       => self::MODEL_ID, // dolphin-2.9.2-qwen2-72b
			'messages'    => $messages,
			'temperature' => 0.7, // Adjust creativity
			'max_tokens'  => 2000 // Ensure enough length for a blog post
		];

		// Request Args
		$args = [
			'body'    => json_encode( $payload ),
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $key
			],
			'timeout' => 60 // Giving AI time to think
		];

		// Send Request
		$response = wp_remote_post( self::API_ENDPOINT, $args );

		if ( is_wp_error( $response ) ) return $response;

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = $body['error']['message'] ?? 'API Error (' . $code . ')';
			return new \WP_Error( 'api_error', $msg );
		}

		// 5. Extract Content
		if ( isset( $body['choices'][0]['message']['content'] ) ) {
			return (string) $body['choices'][0]['message']['content'];
		}

		return new \WP_Error( 'parse_error', 'Invalid API Response from Venice.' );
	}

	/**
	 * Enqueue Frontend CSS.
	 */
	public function enqueue_public_assets(): void {
		wp_enqueue_style( 'urf-public-css', URF_PLUGIN_URL . 'assets/css/unified-rss-public.css', [], URF_VERSION );
	}

	/**
	 * Shortcode.
	 */
	public function render_shortcode( array|string $atts ): string {
		$atts = shortcode_atts( [ 'limit' => 10, 'source' => null ], (array) $atts, 'unified_feed' );
		$items = $this->fetch_feed_items( absint( $atts['limit'] ), $atts['source'] ? absint( $atts['source'] ) : null );

		ob_start();
		echo '<div class="urf-feed-list">';
		if ( empty( $items ) ) {
			echo '<p>No items.</p>';
		} else {
			foreach ( $items as $item ) {
				echo '<div class="urf-item">';
				echo '<h3><a href="' . esc_url( (string) $item['permalink'] ) . '" target="_blank">' . esc_html( (string) $item['title'] ) . '</a></h3>';
				echo '</div>';
			}
		}
		echo '</div>';
		return ob_get_clean() ?: '';
	}
}

// Activation
register_activation_hook( __FILE__, function() { flush_rewrite_rules(); } );

// Init
Unified_RSS_Curator::get_instance();
